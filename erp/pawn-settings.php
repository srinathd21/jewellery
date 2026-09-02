<?php
ob_start();
require __DIR__ . '/_common.php';

function psTableExists($conn, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

function psJson($ok, $message, $data = array(), $status = 200)
{
    // Prevent PHP warnings/notices or whitespace from corrupting AJAX JSON.
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('success' => (bool) $ok, 'message' => (string) $message), $data));
    exit;
}

function psAudit($conn, $businessId, $branchId, $userId, $action, $table, $id, $description, $old = null, $new = null)
{
    if (!psTableExists($conn, 'audit_logs'))
        return;
    $module = 'pawn.settings';
    $oldJson = $old === null ? null : json_encode($old);
    $newJson = $new === null ? null : json_encode($new);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
    $s = $conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    if (!$s)
        return;
    $s->bind_param('iiisssisssss', $businessId, $branchId, $userId, $module, $action, $table, $id, $description, $oldJson, $newJson, $ip, $ua);
    $s->execute();
    $s->close();
}

function psRequireCsrf($csrfToken)
{
    $posted = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if ($posted === '' || !hash_equals((string) $csrfToken, $posted)) {
        psJson(false, 'Invalid CSRF token. Refresh the page and try again.', array(), 403);
    }
}

function psSettingSave($conn, $businessId, $key, $value, $type)
{
    $s = $conn->prepare("INSERT INTO business_settings (business_id,setting_key,setting_value,value_type,is_public) VALUES (?,?,?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),is_public=0");
    if (!$s)
        throw new RuntimeException($conn->error);
    $s->bind_param('isss', $businessId, $key, $value, $type);
    if (!$s->execute())
        throw new RuntimeException($s->error);
    $s->close();
}

$hasSchemes = psTableExists($conn, 'pawn_interest_schemes');
$hasSteps = psTableExists($conn, 'pawn_interest_rate_steps');
$hasBanks = psTableExists($conn, 'pawn_banks');
$hasBusinessSettings = psTableExists($conn, 'business_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    psRequireCsrf($csrfToken);
    $action = trim((string) $_POST['action']);

    try {
        if ($action === 'save_category') {
            $id = max(0, (int) ($_POST['id'] ?? 0));
            $code = strtoupper(trim((string) ($_POST['category_code'] ?? '')));
            $name = trim((string) ($_POST['category_name'] ?? ''));
            $categoryType = trim((string) ($_POST['category_type'] ?? 'Ornament'));
            $metalType = trim((string) ($_POST['metal_type'] ?? ''));
            $purity = trim((string) ($_POST['purity_standard'] ?? ''));
            $minPurity = trim((string) ($_POST['min_purity_percent'] ?? ''));
            $maxPurity = trim((string) ($_POST['max_purity_percent'] ?? ''));
            $maxLoan = max(0, min(100, (float) ($_POST['max_loan_percent'] ?? 70)));
            $storageFee = max(0, (float) ($_POST['storage_fee_percent'] ?? 0));
            $valuation = trim((string) ($_POST['valuation_method'] ?? 'Weight'));
            $requiresCertificate = !empty($_POST['requires_certificate']) ? 1 : 0;
            $requiresValuation = !empty($_POST['requires_valuation']) ? 1 : 0;
            $description = trim((string) ($_POST['description'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;

            if ($code === '' || $name === '')
                throw new RuntimeException('Category code and category name are required.');
            if (!in_array($categoryType, array('Ornament', 'Metal', 'Document', 'Other'), true))
                $categoryType = 'Ornament';
            if ($metalType !== '' && !in_array($metalType, array('Gold', 'Silver', 'Platinum', 'Other'), true))
                $metalType = '';
            if (!in_array($valuation, array('Weight', 'Piece', 'Stone', 'Combined'), true))
                $valuation = 'Weight';
            $minPurityVal = $minPurity === '' ? null : (float) $minPurity;
            $maxPurityVal = $maxPurity === '' ? null : (float) $maxPurity;

            if ($id > 0) {
                $old = null;
                $q = $conn->prepare('SELECT * FROM pawn_categories WHERE id=? AND business_id=? LIMIT 1');
                $q->bind_param('ii', $id, $businessId);
                $q->execute();
                $old = $q->get_result()->fetch_assoc();
                $q->close();
                if (!$old)
                    throw new RuntimeException('Category not found.');
                $s = $conn->prepare('UPDATE pawn_categories SET category_code=?,category_name=?,category_type=?,metal_type=NULLIF(?,\'\'),purity_standard=?,min_purity_percent=?,max_purity_percent=?,max_loan_percent=?,storage_fee_percent=?,valuation_method=?,requires_certificate=?,requires_valuation=?,description=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('sssssddddsiisiii', $code, $name, $categoryType, $metalType, $purity, $minPurityVal, $maxPurityVal, $maxLoan, $storageFee, $valuation, $requiresCertificate, $requiresValuation, $description, $active, $id, $businessId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Update', 'pawn_categories', $id, 'Updated pawn category ' . $code, $old, array('category_code' => $code, 'category_name' => $name));
            } else {
                $s = $conn->prepare('INSERT INTO pawn_categories (business_id,category_code,category_name,category_type,metal_type,purity_standard,min_purity_percent,max_purity_percent,default_interest_percent,max_loan_percent,storage_fee_percent,valuation_method,requires_certificate,requires_valuation,description,is_active,created_by) VALUES (?,?,?,?,NULLIF(?,\'\'),?,?,?,0,?,?,?,?,?,?,?,?)');
                $s->bind_param('isssssddddsiisii', $businessId, $code, $name, $categoryType, $metalType, $purity, $minPurityVal, $maxPurityVal, $maxLoan, $storageFee, $valuation, $requiresCertificate, $requiresValuation, $description, $active, $userId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $id = (int) $s->insert_id;
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Create', 'pawn_categories', $id, 'Created pawn category ' . $code, null, array('category_code' => $code, 'category_name' => $name));
            }
            psJson(true, 'Pawn category saved successfully.');
        }

        if ($action === 'save_scheme') {
            if (!$hasSchemes)
                throw new RuntimeException('Pawn V2 interest tables are not installed. Run the migration first.');
            $id = max(0, (int) ($_POST['id'] ?? 0));
            $code = strtoupper(trim((string) ($_POST['scheme_code'] ?? '')));
            $name = trim((string) ($_POST['scheme_name'] ?? ''));
            $tenureType = trim((string) ($_POST['tenure_type'] ?? 'Fixed Months'));
            $tenureMonths = $tenureType === 'At Closure' ? null : max(1, (int) ($_POST['tenure_months'] ?? 12));
            $method = trim((string) ($_POST['interest_method'] ?? 'Simple'));
            $rounding = trim((string) ($_POST['interest_rounding_method'] ?? 'Nearest Rupee'));
            $locked = !empty($_POST['permanent_escalation_until_closure']) ? 1 : 0;
            $description = trim((string) ($_POST['description'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($code === '' || $name === '')
                throw new RuntimeException('Scheme code and scheme name are required.');
            if (!in_array($tenureType, array('Fixed Months', 'At Closure'), true))
                $tenureType = 'Fixed Months';
            if (!in_array($method, array('Simple', 'Reducing Balance', 'Flat'), true))
                $method = 'Simple';
            if (!in_array($rounding, array('None', 'Nearest Rupee', 'Ceil Rupee', 'Floor Rupee'), true))
                $rounding = 'Nearest Rupee';
            if ($id > 0) {
                $old = null;
                $q = $conn->prepare('SELECT * FROM pawn_interest_schemes WHERE id=? AND business_id=? LIMIT 1');
                $q->bind_param('ii', $id, $businessId);
                $q->execute();
                $old = $q->get_result()->fetch_assoc();
                $q->close();
                if (!$old)
                    throw new RuntimeException('Scheme not found.');
                $s = $conn->prepare('UPDATE pawn_interest_schemes SET scheme_code=?,scheme_name=?,tenure_type=?,tenure_months=?,interest_method=?,interest_rounding_method=?,permanent_escalation_until_closure=?,description=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('sssissisiii', $code, $name, $tenureType, $tenureMonths, $method, $rounding, $locked, $description, $active, $id, $businessId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Update', 'pawn_interest_schemes', $id, 'Updated pawn interest scheme ' . $code, $old, array('scheme_code' => $code, 'scheme_name' => $name));
            } else {
                $s = $conn->prepare('INSERT INTO pawn_interest_schemes (business_id,scheme_code,scheme_name,tenure_type,tenure_months,interest_method,interest_rounding_method,permanent_escalation_until_closure,description,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('isssissisii', $businessId, $code, $name, $tenureType, $tenureMonths, $method, $rounding, $locked, $description, $active, $userId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $id = (int) $s->insert_id;
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Create', 'pawn_interest_schemes', $id, 'Created pawn interest scheme ' . $code, null, array('scheme_code' => $code, 'scheme_name' => $name));
            }
            psJson(true, 'Interest scheme saved successfully.');
        }

        if ($action === 'save_step') {
            if (!$hasSteps)
                throw new RuntimeException('Pawn V2 rate-step table is not installed.');
            $id = max(0, (int) ($_POST['id'] ?? 0));
            $schemeId = max(1, (int) ($_POST['scheme_id'] ?? 0));
            $level = max(1, (int) ($_POST['level_no'] ?? 1));
            $rate = max(0, (float) ($_POST['rate_percent'] ?? 0));
            $cycleType = trim((string) ($_POST['interest_cycle_type'] ?? 'Calendar Month'));
            $cycleValue = max(1, (int) ($_POST['interest_cycle_value'] ?? 1));
            $grace = max(0, (int) ($_POST['grace_days'] ?? 0));
            $miss = max(1, (int) ($_POST['missed_cycles_to_escalate'] ?? 1));
            $nextRaw = trim((string) ($_POST['next_level_no'] ?? ''));
            $next = $nextRaw === '' ? null : max(1, (int) $nextRaw);
            if ($next !== null && $next === $level) {
                throw new RuntimeException('Next Level cannot be the same as the current level. Leave Next Level blank for the final level.');
            }
            $effective = trim((string) ($_POST['escalation_effective'] ?? 'Next Cycle'));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if (!in_array($cycleType, array('Calendar Month', 'Days', 'Months'), true))
                $cycleType = 'Calendar Month';
            if (!in_array($effective, array('Immediately', 'Next Cycle'), true))
                $effective = 'Next Cycle';
            $q = $conn->prepare('SELECT id FROM pawn_interest_schemes WHERE id=? AND business_id=? LIMIT 1');
            $q->bind_param('ii', $schemeId, $businessId);
            $q->execute();
            $ok = $q->get_result()->fetch_assoc();
            $q->close();
            if (!$ok)
                throw new RuntimeException('Invalid interest scheme.');
            if ($id > 0) {
                $old = null;
                $q = $conn->prepare('SELECT * FROM pawn_interest_rate_steps WHERE id=? AND business_id=? LIMIT 1');
                $q->bind_param('ii', $id, $businessId);
                $q->execute();
                $old = $q->get_result()->fetch_assoc();
                $q->close();
                if (!$old)
                    throw new RuntimeException('Rate step not found.');
                $s = $conn->prepare('UPDATE pawn_interest_rate_steps SET scheme_id=?,level_no=?,rate_percent=?,interest_cycle_type=?,interest_cycle_value=?,grace_days=?,missed_cycles_to_escalate=?,next_level_no=?,escalation_effective=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('iidsiiiisiii', $schemeId, $level, $rate, $cycleType, $cycleValue, $grace, $miss, $next, $effective, $active, $id, $businessId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Update', 'pawn_interest_rate_steps', $id, 'Updated pawn interest rate level ' . $level, $old, array('rate_percent' => $rate, 'level_no' => $level));
            } else {
                $s = $conn->prepare('INSERT INTO pawn_interest_rate_steps (business_id,scheme_id,level_no,rate_percent,interest_cycle_type,interest_cycle_value,grace_days,missed_cycles_to_escalate,next_level_no,escalation_effective,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('iiidsiiiisii', $businessId, $schemeId, $level, $rate, $cycleType, $cycleValue, $grace, $miss, $next, $effective, $active, $userId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $id = (int) $s->insert_id;
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Create', 'pawn_interest_rate_steps', $id, 'Created pawn interest rate level ' . $level, null, array('rate_percent' => $rate, 'level_no' => $level));
            }
            psJson(true, 'Interest rate level saved successfully.');
        }

        if ($action === 'save_bank') {
            if (!$hasBanks)
                throw new RuntimeException('Pawn bank table is not installed. Run the Pawn V2 migration first.');
            $id = max(0, (int) ($_POST['id'] ?? 0));
            $code = strtoupper(trim((string) ($_POST['bank_code'] ?? '')));
            $name = trim((string) ($_POST['bank_name'] ?? ''));
            $branch = trim((string) ($_POST['branch_name'] ?? ''));
            $address = trim((string) ($_POST['branch_address'] ?? ''));
            $contact = trim((string) ($_POST['contact_person'] ?? ''));
            $mobile = trim((string) ($_POST['mobile'] ?? ''));
            $account = trim((string) ($_POST['account_number_masked'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($code === '' || $name === '')
                throw new RuntimeException('Bank code and bank name are required.');
            if ($id > 0) {
                $old = null;
                $q = $conn->prepare('SELECT * FROM pawn_banks WHERE id=? AND business_id=? LIMIT 1');
                $q->bind_param('ii', $id, $businessId);
                $q->execute();
                $old = $q->get_result()->fetch_assoc();
                $q->close();
                if (!$old)
                    throw new RuntimeException('Bank not found.');
                $s = $conn->prepare('UPDATE pawn_banks SET bank_code=?,bank_name=?,branch_name=?,branch_address=?,contact_person=?,mobile=?,account_number_masked=?,notes=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('ssssssssiii', $code, $name, $branch, $address, $contact, $mobile, $account, $notes, $active, $id, $businessId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Update', 'pawn_banks', $id, 'Updated pawn bank ' . $code, $old, array('bank_code' => $code, 'bank_name' => $name));
            } else {
                $s = $conn->prepare('INSERT INTO pawn_banks (business_id,bank_code,bank_name,branch_name,branch_address,contact_person,mobile,account_number_masked,notes,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('issssssssii', $businessId, $code, $name, $branch, $address, $contact, $mobile, $account, $notes, $active, $userId);
                if (!$s->execute())
                    throw new RuntimeException($s->error);
                $id = (int) $s->insert_id;
                $s->close();
                psAudit($conn, $businessId, $branchId, $userId, 'Create', 'pawn_banks', $id, 'Created pawn bank ' . $code, null, array('bank_code' => $code, 'bank_name' => $name));
            }
            psJson(true, 'Bank saved successfully.');
        }

        if ($action === 'toggle_active') {
            $target = trim((string) ($_POST['target'] ?? ''));
            $id = max(1, (int) ($_POST['id'] ?? 0));
            $active = !empty($_POST['active']) ? 1 : 0;
            $allowed = array('pawn_categories', 'pawn_interest_schemes', 'pawn_interest_rate_steps', 'pawn_banks');
            if (!in_array($target, $allowed, true) || !psTableExists($conn, $target))
                throw new RuntimeException('Invalid setting record.');
            $s = $conn->prepare("UPDATE `{$target}` SET is_active=? WHERE id=? AND business_id=?");
            $s->bind_param('iii', $active, $id, $businessId);
            if (!$s->execute())
                throw new RuntimeException($s->error);
            $s->close();
            psAudit($conn, $businessId, $branchId, $userId, 'Update', $target, $id, ($active ? 'Activated ' : 'Deactivated ') . $target, null, array('is_active' => $active));
            psJson(true, $active ? 'Activated successfully.' : 'Deactivated successfully.');
        }

        if ($action === 'save_general') {
            if (!$hasBusinessSettings)
                throw new RuntimeException('business_settings table is not available.');
            $settings = array(
                'pawn_auto_escalate_interest' => array(!empty($_POST['pawn_auto_escalate_interest']) ? '1' : '0', 'boolean'),
                'pawn_block_release_if_bank_pledged' => array(!empty($_POST['pawn_block_release_if_bank_pledged']) ? '1' : '0', 'boolean'),
                'pawn_allow_partial_principal' => array(!empty($_POST['pawn_allow_partial_principal']) ? '1' : '0', 'boolean'),
                'pawn_require_id_proof' => array(!empty($_POST['pawn_require_id_proof']) ? '1' : '0', 'boolean'),
                'pawn_require_item_photo' => array(!empty($_POST['pawn_require_item_photo']) ? '1' : '0', 'boolean'),
                'pawn_interest_due_reminder_days' => array((string) max(0, (int) ($_POST['pawn_interest_due_reminder_days'] ?? 3)), 'number'),
                'pawn_bank_interest_reminder_days' => array((string) max(0, (int) ($_POST['pawn_bank_interest_reminder_days'] ?? 7)), 'number'),
                'pawn_default_document_charge' => array(number_format(max(0, (float) ($_POST['pawn_default_document_charge'] ?? 0)), 2, '.', ''), 'number'),
                'pawn_default_other_charge' => array(number_format(max(0, (float) ($_POST['pawn_default_other_charge'] ?? 0)), 2, '.', ''), 'number')
            );
            foreach ($settings as $k => $v)
                psSettingSave($conn, $businessId, $k, $v[0], $v[1]);
            psAudit($conn, $businessId, $branchId, $userId, 'Update', 'business_settings', 0, 'Updated Pawn Broking general settings', null, array_keys($settings));
            psJson(true, 'General pawn settings saved successfully.');
        }

        psJson(false, 'Unknown action.', array(), 400);
    } catch (Throwable $e) {
        psJson(false, $e->getMessage(), array(), 500);
    }
}

$categories = array();
if (psTableExists($conn, 'pawn_categories')) {
    $s = $conn->prepare('SELECT * FROM pawn_categories WHERE business_id=? ORDER BY is_active DESC,category_name');
    $s->bind_param('i', $businessId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $categories[] = $x;
    $s->close();
}
$schemes = array();
$steps = array();
$banks = array();
if ($hasSchemes) {
    $s = $conn->prepare('SELECT * FROM pawn_interest_schemes WHERE business_id=? ORDER BY is_active DESC,scheme_name');
    $s->bind_param('i', $businessId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $schemes[] = $x;
    $s->close();
}
if ($hasSteps) {
    $s = $conn->prepare('SELECT rs.*,s.scheme_name,s.scheme_code FROM pawn_interest_rate_steps rs INNER JOIN pawn_interest_schemes s ON s.id=rs.scheme_id WHERE rs.business_id=? ORDER BY s.scheme_name,rs.level_no');
    $s->bind_param('i', $businessId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $steps[] = $x;
    $s->close();
}
if ($hasBanks) {
    $s = $conn->prepare('SELECT * FROM pawn_banks WHERE business_id=? ORDER BY is_active DESC,bank_name,branch_name');
    $s->bind_param('i', $businessId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $banks[] = $x;
    $s->close();
}

$general = array(
    'pawn_auto_escalate_interest' => '1',
    'pawn_block_release_if_bank_pledged' => '1',
    'pawn_allow_partial_principal' => '1',
    'pawn_require_id_proof' => '1',
    'pawn_require_item_photo' => '0',
    'pawn_interest_due_reminder_days' => '3',
    'pawn_bank_interest_reminder_days' => '7',
    'pawn_default_document_charge' => '0.00',
    'pawn_default_other_charge' => '0.00'
);
if ($hasBusinessSettings) {
    $keys = array_keys($general);
    $quoted = array();
    foreach ($keys as $k)
        $quoted[] = "'" . $conn->real_escape_string($k) . "'";
    $r = $conn->query('SELECT setting_key,setting_value FROM business_settings WHERE business_id=' . (int) $businessId . ' AND setting_key IN (' . implode(',', $quoted) . ')');
    if ($r) {
        while ($x = $r->fetch_assoc())
            $general[$x['setting_key']] = $x['setting_value'];
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Settings</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .settings-tabs {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--card-bg)
        }

        .settings-tab {
            border: 0;
            background: transparent;
            padding: 9px 13px;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 800;
            color: var(--muted)
        }

        .settings-tab.active {
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .tab-pane-x {
            display: none
        }

        .tab-pane-x.active {
            display: block
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px
        }

        .form-grid .span-2 {
            grid-column: span 2
        }

        .form-grid .span-4 {
            grid-column: 1/-1
        }

        .settings-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 390px;
            gap: 14px;
            align-items: start
        }

        .sticky-form {
            position: sticky;
            top: 82px
        }

        .compact-table th {
            font-size: 9px;
            text-transform: uppercase;
            white-space: nowrap;
            padding: 9px
        }

        .compact-table td {
            font-size: 10px;
            padding: 9px;
            vertical-align: middle
        }

        .badge-soft {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 9px;
            font-weight: 800
        }

        .status-on {
            color: #168449
        }

        .status-off {
            color: #8a94a1
        }

        .mini-actions {
            display: flex;
            gap: 5px;
            justify-content: flex-end
        }

        .mini-action {
            border: 1px solid var(--line);
            background: var(--card-bg);
            border-radius: 7px;
            padding: 5px 8px;
            font-size: 9px
        }

        .migration-warning {
            padding: 12px;
            border: 1px solid #e4b54c;
            background: #fff8e5;
            color: #845d00;
            border-radius: 10px;
            font-size: 10px
        }

        .switch-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 11px 0;
            border-bottom: 1px dashed var(--line)
        }

        .switch-row:last-child {
            border-bottom: 0
        }

        .switch-row strong {
            font-size: 11px
        }

        .switch-row span {
            font-size: 9px;
            color: var(--muted);
            display: block;
            margin-top: 2px
        }

        .toast-x {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 25000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 700
        }

        .toast-ok {
            background: #168449
        }

        .toast-bad {
            background: #c0392b
        }

        .modal-card {
            border: 0;
            border-radius: 16px;
            overflow: hidden;
            background: var(--card-bg);
            color: var(--text-color);
            box-shadow: 0 24px 70px rgba(0, 0, 0, .2)
        }

        .modal-card .modal-header,
        .modal-card .modal-footer {
            border-color: var(--line);
            padding: 16px 18px
        }

        .modal-card .modal-body {
            padding: 18px
        }

        .modal-form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px
        }

        .modal-form-grid .span-2 {
            grid-column: span 2
        }

        .modal-form-grid .span-3 {
            grid-column: 1/-1
        }

        .general-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px
        }

        .summary-tile {
            border: 1px solid var(--line);
            border-radius: 11px;
            padding: 14px;
            background: var(--card-bg)
        }

        .summary-tile span {
            display: block;
            font-size: 9px;
            color: var(--muted);
            margin-bottom: 5px
        }

        .summary-tile strong {
            font-size: 12px
        }

        .page-head .btn-theme {
            min-height: 34px;
            padding: 7px 11px
        }

        @media(max-width:1100px) {
            .settings-layout {
                grid-template-columns: 1fr
            }

            .sticky-form {
                position: static
            }

            .form-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            .form-grid .span-4 {
                grid-column: 1/-1
            }
        }

        @media(max-width:767px) {

            .form-grid,
            .modal-form-grid,
            .general-grid {
                grid-template-columns: 1fr
            }

            .form-grid .span-2,
            .form-grid .span-4,
            .modal-form-grid .span-2,
            .modal-form-grid .span-3 {
                grid-column: auto
            }

            .settings-tab {
                flex: 1 1 calc(50% - 6px)
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="page-card mb-3">
                <div class="page-head">
                    <div>
                        <div class="page-title">Pawn Settings</div>
                        <div class="small text-muted">Manage categories, dynamic customer interest rules, banks and core
                            pawn controls.</div>
                    </div><a href="pawn-manage.php" class="btn-soft"><i class="fa-solid fa-arrow-left"></i> Pawn
                        Manage</a>
                </div>
            </div>

            <?php if (!$hasSchemes || !$hasSteps || !$hasBanks): ?>
                <div class="migration-warning mb-3"><strong>Pawn V2 migration is not fully installed.</strong> Interest
                    Scheme / Rate Level / Bank tabs will remain unavailable until
                    <code>pawn-broking-v2-interest-bank-migration.sql</code> is executed.</div>
            <?php endif; ?>

            <div class="settings-tabs mb-3" id="settingsTabs">
                <button class="settings-tab active" data-tab="interest"><i class="fa-solid fa-percent me-1"></i>
                    Interest Rules</button>
                <button class="settings-tab" data-tab="banks"><i class="fa-solid fa-building-columns me-1"></i>
                    Banks</button>
                <button class="settings-tab" data-tab="general"><i class="fa-solid fa-sliders me-1"></i>
                    General</button>
            </div>

            <section class="tab-pane-x active" data-pane="interest">
                <?php if (!$hasSchemes || !$hasSteps): ?>
                    <div class="page-card">
                        <div class="card-body-x text-muted">Run the Pawn V2 migration first to enable dynamic interest
                            schemes and escalation levels.</div>
                    </div>
                <?php else: ?>
                    <div class="page-card mb-3">
                        <div class="page-head">
                            <div>
                                <div class="section-title">Interest Schemes</div>
                                <div class="small text-muted">Create and manage customer pawn interest plans.</div>
                            </div>
                            <div class="d-flex align-items-center gap-2"><span class="badge-soft"><?= count($schemes) ?>
                                    schemes</span><button type="button" class="btn-theme" id="addSchemeBtn"><i
                                        class="fa-solid fa-plus me-1"></i>Add Scheme</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table compact-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Scheme</th>
                                        <th>Tenure</th>
                                        <th>Lock</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$schemes): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted p-4">No interest schemes found.</td>
                                        </tr><?php else:
                                        foreach ($schemes as $row): ?>
                                            <tr>
                                                <td><?= e($row['scheme_code']) ?></td>
                                                <td><strong><?= e($row['scheme_name']) ?></strong></td>
                                                <td><?= e($row['tenure_type'] === 'At Closure' ? 'At Closure' : $row['tenure_months'] . ' months') ?>
                                                </td>
                                                <td><?= !empty($row['permanent_escalation_until_closure']) ? 'Permanent' : 'No' ?></td>
                                                <td class="<?= !empty($row['is_active']) ? 'status-on' : 'status-off' ?>">
                                                    <?= !empty($row['is_active']) ? 'Active' : 'Inactive' ?></td>
                                                <td>
                                                    <div class="mini-actions"><button class="mini-action edit-scheme" type="button"
                                                            data-row='<?= e(json_encode($row)) ?>'><i class="fa-solid fa-pen"></i>
                                                            Edit</button><button class="mini-action toggle-record" type="button"
                                                            data-target="pawn_interest_schemes" data-id="<?= (int) $row['id'] ?>"
                                                            data-active="<?= !empty($row['is_active']) ? 0 : 1 ?>"><?= !empty($row['is_active']) ? 'Disable' : 'Enable' ?></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="page-card mb-3">
                        <div class="page-head">
                            <div>
                                <div class="section-title">Rate Levels / Escalation Rules</div>
                                <div class="small text-muted">Configure rate, due cycle, grace period and next level for
                                    each scheme.</div>
                            </div>
                            <div class="d-flex align-items-center gap-2"><span class="badge-soft"><?= count($steps) ?>
                                    levels</span><button type="button" class="btn-theme" id="addStepBtn"><i
                                        class="fa-solid fa-plus me-1"></i>Add Rate Level</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table compact-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Scheme</th>
                                        <th>Level</th>
                                        <th>Rate</th>
                                        <th>Due Cycle</th>
                                        <th>Grace</th>
                                        <th>After Miss</th>
                                        <th>Next</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$steps): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted p-4">No rate levels found.</td>
                                        </tr><?php else:
                                        foreach ($steps as $row): ?>
                                            <tr>
                                                <td><?= e($row['scheme_code']) ?></td>
                                                <td>L<?= (int) $row['level_no'] ?></td>
                                                <td><strong><?= number_format((float) $row['rate_percent'], 3) ?>%</strong></td>
                                                <td><?= e($row['interest_cycle_type']) ?> / <?= (int) $row['interest_cycle_value'] ?>
                                                </td>
                                                <td><?= (int) $row['grace_days'] ?> day(s)</td>
                                                <td><?= (int) $row['missed_cycles_to_escalate'] ?> miss</td>
                                                <td><?= $row['next_level_no'] !== null ? 'L' . (int) $row['next_level_no'] : 'Final' ?></td>
                                                <td class="<?= !empty($row['is_active']) ? 'status-on' : 'status-off' ?>">
                                                    <?= !empty($row['is_active']) ? 'Active' : 'Inactive' ?></td>
                                                <td>
                                                    <div class="mini-actions"><button type="button" class="mini-action edit-step"
                                                            data-row='<?= e(json_encode($row)) ?>'><i class="fa-solid fa-pen"></i>
                                                            Edit</button><button type="button" class="mini-action toggle-record"
                                                            data-target="pawn_interest_rate_steps" data-id="<?= (int) $row['id'] ?>"
                                                            data-active="<?= !empty($row['is_active']) ? 0 : 1 ?>"><?= !empty($row['is_active']) ? 'Disable' : 'Enable' ?></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tab-pane-x" data-pane="banks">
                <?php if (!$hasBanks): ?>
                    <div class="page-card">
                        <div class="card-body-x text-muted">Run the Pawn V2 migration first to enable Bank Master.</div>
                    </div><?php else: ?>
                    <div class="page-card">
                        <div class="page-head">
                            <div>
                                <div class="section-title">Banks / Bank Branches</div>
                                <div class="small text-muted">Manage banks used for re-pledge and bank-side pawn loans.
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2"><span class="badge-soft"><?= count($banks) ?>
                                    records</span><button type="button" class="btn-theme" id="addBankBtn"><i
                                        class="fa-solid fa-plus me-1"></i>Add Bank</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table compact-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Bank</th>
                                        <th>Branch</th>
                                        <th>Contact</th>
                                        <th>Account</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$banks): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted p-4">No banks found.</td>
                                        </tr><?php else:
                                        foreach ($banks as $row): ?>
                                            <tr>
                                                <td><?= e($row['bank_code']) ?></td>
                                                <td><strong><?= e($row['bank_name']) ?></strong></td>
                                                <td><?= e($row['branch_name'] ?: '—') ?></td>
                                                <td><?= e($row['contact_person'] ?: '—') ?>
                                                    <div class="text-muted"><?= e($row['mobile'] ?: '') ?></div>
                                                </td>
                                                <td><?= e($row['account_number_masked'] ?: '—') ?></td>
                                                <td class="<?= !empty($row['is_active']) ? 'status-on' : 'status-off' ?>">
                                                    <?= !empty($row['is_active']) ? 'Active' : 'Inactive' ?></td>
                                                <td>
                                                    <div class="mini-actions"><button type="button" class="mini-action edit-bank"
                                                            data-row='<?= e(json_encode($row)) ?>'><i class="fa-solid fa-pen"></i>
                                                            Edit</button><button type="button" class="mini-action toggle-record"
                                                            data-target="pawn_banks" data-id="<?= (int) $row['id'] ?>"
                                                            data-active="<?= !empty($row['is_active']) ? 0 : 1 ?>"><?= !empty($row['is_active']) ? 'Disable' : 'Enable' ?></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tab-pane-x" data-pane="general">
                <div class="page-card">
                    <div class="page-head">
                        <div>
                            <div class="section-title">Business Rules</div>
                            <div class="small text-muted">Core pawn behavior and default charge settings.</div>
                        </div><button type="button" class="btn-theme" id="editGeneralBtn"><i
                                class="fa-solid fa-pen me-1"></i>Edit General Settings</button>
                    </div>
                    <div class="card-body-x">
                        <div class="general-grid">
                            <div class="summary-tile"><span>Automatic Interest
                                    Escalation</span><strong><?= $general['pawn_auto_escalate_interest'] === '1' ? 'Enabled' : 'Disabled' ?></strong>
                            </div>
                            <div class="summary-tile"><span>Block Release if
                                    Bank-Pledged</span><strong><?= $general['pawn_block_release_if_bank_pledged'] === '1' ? 'Enabled' : 'Disabled' ?></strong>
                            </div>
                            <div class="summary-tile"><span>Partial Principal
                                    Payment</span><strong><?= $general['pawn_allow_partial_principal'] === '1' ? 'Enabled' : 'Disabled' ?></strong>
                            </div>
                            <div class="summary-tile"><span>Require Customer ID
                                    Proof</span><strong><?= $general['pawn_require_id_proof'] === '1' ? 'Enabled' : 'Disabled' ?></strong>
                            </div>
                            <div class="summary-tile"><span>Require Pawn Item
                                    Photo</span><strong><?= $general['pawn_require_item_photo'] === '1' ? 'Enabled' : 'Disabled' ?></strong>
                            </div>
                            <div class="summary-tile"><span>Customer
                                    Reminder</span><strong><?= (int) $general['pawn_interest_due_reminder_days'] ?>
                                    days</strong></div>
                            <div class="summary-tile"><span>Bank
                                    Reminder</span><strong><?= (int) $general['pawn_bank_interest_reminder_days'] ?>
                                    days</strong></div>
                            <div class="summary-tile"><span>Default Document
                                    Charge</span><strong>₹<?= number_format((float) $general['pawn_default_document_charge'], 2) ?></strong>
                            </div>
                            <div class="summary-tile"><span>Default Other
                                    Charge</span><strong>₹<?= number_format((float) $general['pawn_default_other_charge'], 2) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="modal fade" id="schemeModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content modal-card">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="schemeModalTitle">Add Interest Scheme</h5>
                                <div class="small text-muted">Create or update an interest scheme.</div>
                            </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="schemeForm">
                            <div class="modal-body"><input type="hidden" name="action" value="save_scheme"><input
                                    type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden"
                                    name="id">
                                <div class="modal-form-grid">
                                    <div><label class="form-label">Scheme Code *</label><input class="form-control"
                                            name="scheme_code" required></div>
                                    <div class="span-2"><label class="form-label">Scheme Name *</label><input
                                            class="form-control" name="scheme_name" required></div>
                                    <div><label class="form-label">Tenure Type</label><select class="form-select"
                                            name="tenure_type">
                                            <option>Fixed Months</option>
                                            <option>At Closure</option>
                                        </select></div>
                                    <div><label class="form-label">Tenure Months</label><input type="number" min="1"
                                            class="form-control" name="tenure_months" value="12"></div>
                                    <div><label class="form-label">Interest Method</label><select class="form-select"
                                            name="interest_method">
                                            <option>Simple</option>
                                            <option>Reducing Balance</option>
                                            <option>Flat</option>
                                        </select></div>
                                    <div><label class="form-label">Rounding</label><select class="form-select"
                                            name="interest_rounding_method">
                                            <option>Nearest Rupee</option>
                                            <option>Ceil Rupee</option>
                                            <option>Floor Rupee</option>
                                            <option>None</option>
                                        </select></div>
                                    <div class="span-3"><label class="form-label">Description</label><textarea
                                            class="form-control" rows="3" name="description"></textarea></div>
                                </div>
                                <div class="d-flex gap-4 mt-3"><label><input type="checkbox"
                                            name="permanent_escalation_until_closure" value="1" checked> Escalated rate
                                        locked until closure</label><label><input type="checkbox" name="is_active"
                                            value="1" checked> Active</label></div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn-soft"
                                    data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-theme">Save
                                    Scheme</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="stepModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content modal-card">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="stepModalTitle">Add Rate Level</h5>
                                <div class="small text-muted">Configure escalation and due-cycle behavior.</div>
                            </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="stepForm">
                            <div class="modal-body"><input type="hidden" name="action" value="save_step"><input
                                    type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden"
                                    name="id">
                                <div class="modal-form-grid">
                                    <div class="span-2"><label class="form-label">Scheme *</label><select
                                            class="form-select" name="scheme_id" required>
                                            <option value="">Select Scheme</option><?php foreach ($schemes as $s): ?>
                                                <option value="<?= (int) $s['id'] ?>">
                                                    <?= e($s['scheme_code'] . ' - ' . $s['scheme_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select></div>
                                    <div><label class="form-label">Level *</label><input class="form-control"
                                            type="number" min="1" name="level_no" value="1" required></div>
                                    <div><label class="form-label">Rate % *</label><input class="form-control"
                                            type="number" min="0" step="0.001" name="rate_percent" value="1.500"
                                            required></div>
                                    <div><label class="form-label">Due Cycle Type</label><select class="form-select"
                                            name="interest_cycle_type">
                                            <option>Calendar Month</option>
                                            <option>Days</option>
                                            <option>Months</option>
                                        </select></div>
                                    <div><label class="form-label">Cycle Value</label><input class="form-control"
                                            type="number" min="1" name="interest_cycle_value" value="1"></div>
                                    <div><label class="form-label">Grace Days</label><input class="form-control"
                                            type="number" min="0" name="grace_days" value="0"></div>
                                    <div><label class="form-label">Misses to Escalate</label><input class="form-control"
                                            type="number" min="1" name="missed_cycles_to_escalate" value="1"></div>
                                    <div><label class="form-label">Next Level</label><input class="form-control"
                                            type="number" min="1" name="next_level_no" placeholder="Blank = final">
                                    </div>
                                    <div class="span-2"><label class="form-label">Escalation Effective</label><select
                                            class="form-select" name="escalation_effective">
                                            <option>Next Cycle</option>
                                            <option>Immediately</option>
                                        </select></div>
                                </div>
                                <div class="mt-3"><label><input type="checkbox" name="is_active" value="1" checked>
                                        Active</label></div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn-soft"
                                    data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-theme">Save
                                    Rate Level</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="bankModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content modal-card">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="bankModalTitle">Add Bank</h5>
                                <div class="small text-muted">Create or update bank and branch details.</div>
                            </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="bankForm">
                            <div class="modal-body"><input type="hidden" name="action" value="save_bank"><input
                                    type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden"
                                    name="id">
                                <div class="modal-form-grid">
                                    <div><label class="form-label">Bank Code *</label><input class="form-control"
                                            name="bank_code" required></div>
                                    <div class="span-2"><label class="form-label">Bank Name *</label><input
                                            class="form-control" name="bank_name" required></div>
                                    <div><label class="form-label">Branch</label><input class="form-control"
                                            name="branch_name"></div>
                                    <div class="span-2"><label class="form-label">Branch Address</label><input
                                            class="form-control" name="branch_address"></div>
                                    <div><label class="form-label">Contact Person</label><input class="form-control"
                                            name="contact_person"></div>
                                    <div><label class="form-label">Mobile</label><input class="form-control"
                                            name="mobile"></div>
                                    <div class="span-2"><label class="form-label">Account No. / Masked</label><input
                                            class="form-control" name="account_number_masked" placeholder="XXXX1234">
                                    </div>
                                    <div class="span-3"><label class="form-label">Notes</label><textarea
                                            class="form-control" rows="3" name="notes"></textarea></div>
                                </div>
                                <div class="mt-3"><label><input type="checkbox" name="is_active" value="1" checked>
                                        Active</label></div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn-soft"
                                    data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-theme">Save
                                    Bank</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="generalModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content modal-card">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">General Pawn Settings</h5>
                                <div class="small text-muted">Update business-wide pawn behavior.</div>
                            </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="generalForm">
                            <div class="modal-body"><input type="hidden" name="action" value="save_general"><input
                                    type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <div class="switch-row">
                                    <div><strong>Automatic Interest Escalation</strong><span>Move permanently to the
                                            configured next rate when a due passes grace without full payment.</span>
                                    </div><input type="checkbox" name="pawn_auto_escalate_interest" value="1"
                                        <?= $general['pawn_auto_escalate_interest'] === '1' ? 'checked' : '' ?>>
                                </div>
                                <div class="switch-row">
                                    <div><strong>Block Customer Gold Release if Bank-Pledged</strong><span>Do not
                                            release customer gold while bank collateral is active.</span></div><input
                                        type="checkbox" name="pawn_block_release_if_bank_pledged" value="1"
                                        <?= $general['pawn_block_release_if_bank_pledged'] === '1' ? 'checked' : '' ?>>
                                </div>
                                <div class="switch-row">
                                    <div><strong>Allow Partial Principal Payment</strong><span>Permit principal
                                            reductions before final closure.</span></div><input type="checkbox"
                                        name="pawn_allow_partial_principal" value="1"
                                        <?= $general['pawn_allow_partial_principal'] === '1' ? 'checked' : '' ?>>
                                </div>
                                <div class="switch-row">
                                    <div><strong>Require Customer ID Proof</strong><span>Require ID proof during pawn
                                            registration.</span></div><input type="checkbox"
                                        name="pawn_require_id_proof" value="1"
                                        <?= $general['pawn_require_id_proof'] === '1' ? 'checked' : '' ?>>
                                </div>
                                <div class="switch-row">
                                    <div><strong>Require Pawn Item Photo</strong><span>Require an item photo for newly
                                            registered pawn items.</span></div><input type="checkbox"
                                        name="pawn_require_item_photo" value="1"
                                        <?= $general['pawn_require_item_photo'] === '1' ? 'checked' : '' ?>>
                                </div>
                                <div class="modal-form-grid mt-3">
                                    <div><label class="form-label">Customer Reminder Days</label><input
                                            class="form-control" type="number" min="0"
                                            name="pawn_interest_due_reminder_days"
                                            value="<?= e($general['pawn_interest_due_reminder_days']) ?>"></div>
                                    <div><label class="form-label">Bank Reminder Days</label><input class="form-control"
                                            type="number" min="0" name="pawn_bank_interest_reminder_days"
                                            value="<?= e($general['pawn_bank_interest_reminder_days']) ?>"></div>
                                    <div><label class="form-label">Default Document Charge</label><input
                                            class="form-control" type="number" step="0.01" min="0"
                                            name="pawn_default_document_charge"
                                            value="<?= e($general['pawn_default_document_charge']) ?>"></div>
                                    <div><label class="form-label">Default Other Charge</label><input
                                            class="form-control" type="number" step="0.01" min="0"
                                            name="pawn_default_other_charge"
                                            value="<?= e($general['pawn_default_other_charge']) ?>"></div>
                                </div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn-soft"
                                    data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-theme">Save
                                    General Settings</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';
            const tabs = [...document.querySelectorAll('.settings-tab')], panes = [...document.querySelectorAll('.tab-pane-x')];
            function toast(ok, msg) { const d = document.createElement('div'); d.className = 'toast-x ' + (ok ? 'toast-ok' : 'toast-bad'); d.textContent = msg; document.body.appendChild(d); setTimeout(() => d.remove(), 3200) }
            async function postForm(form) { const r = await fetch('pawn-settings.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } }); const raw = await r.text(); let j = {}; try { j = JSON.parse(raw); } catch (e) { const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(); throw new Error(clean.slice(0, 260) || 'Invalid server response.'); } if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function activate(name) { tabs.forEach(b => b.classList.toggle('active', b.dataset.tab === name)); panes.forEach(p => p.classList.toggle('active', p.dataset.pane === name)); try { localStorage.setItem('pawnSettingsTab', name) } catch (e) { } }
            tabs.forEach(b => b.onclick = () => activate(b.dataset.tab));
            try { const saved = localStorage.getItem('pawnSettingsTab'); if (saved && document.querySelector('[data-pane="' + saved + '"]')) activate(saved) } catch (e) { }
            const getModal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
            function resetForm(id) { const f = document.getElementById(id); if (!f) return; f.reset(); if (f.elements.id) f.elements.id.value = ''; }
            function fill(formId, row) { const f = document.getElementById(formId); if (!f) return; resetForm(formId); Object.keys(row).forEach(k => { const el = f.elements[k]; if (!el) return; if (el.type === 'checkbox') el.checked = String(row[k]) === '1'; else el.value = row[k] === null ? '' : row[k] }); }
            [['schemeForm', 'schemeModal'], ['stepForm', 'stepModal'], ['bankForm', 'bankModal'], ['generalForm', 'generalModal']].forEach(([fid, mid]) => { const f = document.getElementById(fid); if (!f) return; f.onsubmit = async e => { e.preventDefault(); const b = f.querySelector('button[type="submit"]'); if (b) b.disabled = true; try { const j = await postForm(f); toast(true, j.message); getModal(mid).hide(); setTimeout(() => location.reload(), 450) } catch (err) { toast(false, err.message) } finally { if (b) b.disabled = false } } });
            const addScheme = document.getElementById('addSchemeBtn'); if (addScheme) addScheme.onclick = () => { resetForm('schemeForm'); document.getElementById('schemeModalTitle').textContent = 'Add Interest Scheme'; getModal('schemeModal').show() };
            const addStep = document.getElementById('addStepBtn'); if (addStep) addStep.onclick = () => { resetForm('stepForm'); document.getElementById('stepModalTitle').textContent = 'Add Rate Level'; getModal('stepModal').show() };
            const addBank = document.getElementById('addBankBtn'); if (addBank) addBank.onclick = () => { resetForm('bankForm'); document.getElementById('bankModalTitle').textContent = 'Add Bank'; getModal('bankModal').show() };
            const editGeneral = document.getElementById('editGeneralBtn'); if (editGeneral) editGeneral.onclick = () => getModal('generalModal').show();
            document.querySelectorAll('.edit-scheme').forEach(b => b.onclick = () => { fill('schemeForm', JSON.parse(b.dataset.row)); document.getElementById('schemeModalTitle').textContent = 'Edit Interest Scheme'; getModal('schemeModal').show() });
            document.querySelectorAll('.edit-step').forEach(b => b.onclick = () => { fill('stepForm', JSON.parse(b.dataset.row)); document.getElementById('stepModalTitle').textContent = 'Edit Rate Level'; getModal('stepModal').show() });
            document.querySelectorAll('.edit-bank').forEach(b => b.onclick = () => { fill('bankForm', JSON.parse(b.dataset.row)); document.getElementById('bankModalTitle').textContent = 'Edit Bank'; getModal('bankModal').show() });
            document.querySelectorAll('.toggle-record').forEach(b => b.onclick = async () => { if (!confirm((b.dataset.active === '1' ? 'Activate' : 'Deactivate') + ' this record?')) return; const fd = new FormData(); fd.append('action', 'toggle_active'); fd.append('csrf_token', <?= json_encode($csrfToken) ?>); fd.append('target', b.dataset.target); fd.append('id', b.dataset.id); fd.append('active', b.dataset.active); try { const r = await fetch('pawn-settings.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }); const raw = await r.text(); let j = {}; try { j = JSON.parse(raw); } catch (err) { const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(); throw new Error(clean.slice(0, 260) || 'Invalid server response.'); } if (!r.ok || !j.success) throw new Error(j.message || 'Unable to update'); toast(true, j.message); setTimeout(() => location.reload(), 400) } catch (e) { toast(false, e.message) } });
        })();
    </script>
</body>

</html>