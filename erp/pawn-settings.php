<?php
require __DIR__ . '/_common.php';

function psTableExists($conn, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

$hasSchemes = psTableExists($conn, 'pawn_interest_schemes');
$hasSteps = psTableExists($conn, 'pawn_interest_rate_steps');
$hasBanks = psTableExists($conn, 'pawn_banks');
$hasBusinessSettings = psTableExists($conn, 'business_settings');

$categories=array();
if(psTableExists($conn,'pawn_categories')){
    $s=$conn->prepare('SELECT * FROM pawn_categories WHERE business_id=? ORDER BY is_active DESC,category_name');
    $s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$categories[]=$x;$s->close();
}
$schemes=array();$steps=array();$banks=array();
if($hasSchemes){$s=$conn->prepare('SELECT * FROM pawn_interest_schemes WHERE business_id=? ORDER BY is_active DESC,scheme_name');$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$schemes[]=$x;$s->close();}
if($hasSteps){$s=$conn->prepare('SELECT rs.*,s.scheme_name,s.scheme_code FROM pawn_interest_rate_steps rs INNER JOIN pawn_interest_schemes s ON s.id=rs.scheme_id WHERE rs.business_id=? ORDER BY s.scheme_name,rs.level_no');$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$steps[]=$x;$s->close();}
if($hasBanks){$s=$conn->prepare('SELECT * FROM pawn_banks WHERE business_id=? ORDER BY is_active DESC,bank_name,branch_name');$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$banks[]=$x;$s->close();}

$general=array(
    'pawn_auto_escalate_interest'=>'1','pawn_block_release_if_bank_pledged'=>'1','pawn_allow_partial_principal'=>'1','pawn_require_id_proof'=>'1','pawn_require_item_photo'=>'0','pawn_interest_due_reminder_days'=>'3','pawn_bank_interest_reminder_days'=>'7','pawn_default_document_charge'=>'0.00','pawn_default_other_charge'=>'0.00'
);
if($hasBusinessSettings){
    $keys=array_keys($general);$quoted=array();foreach($keys as $k)$quoted[]="'".$conn->real_escape_string($k)."'";
    $r=$conn->query('SELECT setting_key,setting_value FROM business_settings WHERE business_id='.(int)$businessId.' AND setting_key IN ('.implode(',',$quoted).')');
    if($r){while($x=$r->fetch_assoc())$general[$x['setting_key']]=$x['setting_value'];}
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Settings</title>
    <?php include('includes/links.php'); require __DIR__ . '/_style.php'; ?>
    <style>
        .settings-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:8px;border:1px solid var(--line);border-radius:12px;background:var(--card-bg)}
        .settings-tab{border:0;background:transparent;padding:9px 13px;border-radius:9px;font-size:11px;font-weight:800;color:var(--muted)}
        .settings-tab.active{background:var(--primary-soft);color:var(--primary-dark)}
        .tab-pane-x{display:none}.tab-pane-x.active{display:block}
        .form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .form-grid .span-2{grid-column:span 2}.form-grid .span-4{grid-column:1/-1}
        .settings-layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:14px;align-items:start}
        .sticky-form{position:sticky;top:82px}
        .compact-table th{font-size:9px;text-transform:uppercase;white-space:nowrap;padding:9px}.compact-table td{font-size:10px;padding:9px;vertical-align:middle}
        .badge-soft{display:inline-flex;padding:4px 8px;border-radius:999px;background:var(--primary-soft);color:var(--primary-dark);font-size:9px;font-weight:800}
        .status-on{color:#168449}.status-off{color:#8a94a1}.mini-actions{display:flex;gap:5px;justify-content:flex-end}
        .mini-action{border:1px solid var(--line);background:var(--card-bg);border-radius:7px;padding:5px 8px;font-size:9px}
        .migration-warning{padding:12px;border:1px solid #e4b54c;background:#fff8e5;color:#845d00;border-radius:10px;font-size:10px}
        .switch-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 0;border-bottom:1px dashed var(--line)}
        .switch-row:last-child{border-bottom:0}.switch-row strong{font-size:11px}.switch-row span{font-size:9px;color:var(--muted);display:block;margin-top:2px}
        .toast-x{position:fixed;right:18px;top:78px;z-index:25000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:700}.toast-ok{background:#168449}.toast-bad{background:#c0392b}
        @media(max-width:1100px){.settings-layout{grid-template-columns:1fr}.sticky-form{position:static}.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid .span-4{grid-column:1/-1}}
        @media(max-width:767px){.form-grid{grid-template-columns:1fr}.form-grid .span-2,.form-grid .span-4{grid-column:auto}.settings-tab{flex:1 1 calc(50% - 6px)}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main"><?php include('includes/nav.php'); ?>
<div class="content-wrap">
    <div class="page-card mb-3"><div class="page-head"><div><div class="page-title">Pawn Settings</div><div class="small text-muted">Manage categories, dynamic customer interest rules, banks and core pawn controls.</div></div><a href="pawn-manage.php" class="btn-soft"><i class="fa-solid fa-arrow-left"></i> Pawn Manage</a></div></div>

    <?php if(!$hasSchemes || !$hasSteps || !$hasBanks): ?>
    <div class="migration-warning mb-3"><strong>Pawn V2 migration is not fully installed.</strong> Interest Scheme / Rate Level / Bank tabs will remain unavailable until <code>pawn-broking-v2-interest-bank-migration.sql</code> is executed.</div>
    <?php endif; ?>

    <div class="settings-tabs mb-3" id="settingsTabs">
        <button class="settings-tab active" data-tab="categories"><i class="fa-solid fa-tags me-1"></i> Categories</button>
        <button class="settings-tab" data-tab="interest"><i class="fa-solid fa-percent me-1"></i> Interest Rules</button>
        <button class="settings-tab" data-tab="banks"><i class="fa-solid fa-building-columns me-1"></i> Banks</button>
        <button class="settings-tab" data-tab="general"><i class="fa-solid fa-sliders me-1"></i> General</button>
    </div>

    <section class="tab-pane-x active" data-pane="categories">
        <div class="settings-layout">
            <div class="page-card"><div class="page-head"><div class="section-title">Pawn Categories</div><span class="badge-soft"><?= count($categories) ?> records</span></div><div class="table-responsive"><table class="table compact-table align-middle"><thead><tr><th>Code</th><th>Name</th><th>Metal</th><th>Loan %</th><th>Valuation</th><th>Status</th><th></th></tr></thead><tbody>
            <?php if(!$categories): ?><tr><td colspan="7" class="text-center text-muted p-4">No categories found.</td></tr><?php else: foreach($categories as $row): ?>
            <tr><td><?= e($row['category_code']) ?></td><td><strong><?= e($row['category_name']) ?></strong><div class="text-muted"><?= e($row['category_type']) ?></div></td><td><?= e($row['metal_type'] ?: '—') ?></td><td><?= number_format((float)$row['max_loan_percent'],2) ?>%</td><td><?= e($row['valuation_method']) ?></td><td class="<?= !empty($row['is_active'])?'status-on':'status-off' ?>"><?= !empty($row['is_active'])?'Active':'Inactive' ?></td><td><div class="mini-actions"><button type="button" class="mini-action edit-category" data-row='<?= e(json_encode($row)) ?>'>Edit</button><button type="button" class="mini-action toggle-record" data-target="pawn_categories" data-id="<?= (int)$row['id'] ?>" data-active="<?= !empty($row['is_active'])?0:1 ?>"><?= !empty($row['is_active'])?'Disable':'Enable' ?></button></div></td></tr>
            <?php endforeach; endif; ?>
            </tbody></table></div></div>
            <div class="page-card sticky-form"><div class="page-head"><div class="section-title" id="categoryFormTitle">Add Category</div></div><div class="card-body-x"><form id="categoryForm"><input type="hidden" name="action" value="save_category"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="id" value="">
                <div class="form-grid"><div><label class="form-label">Code *</label><input class="form-control" name="category_code" required></div><div><label class="form-label">Name *</label><input class="form-control" name="category_name" required></div><div><label class="form-label">Type</label><select class="form-select" name="category_type"><option>Ornament</option><option>Metal</option><option>Document</option><option>Other</option></select></div><div><label class="form-label">Metal</label><select class="form-select" name="metal_type"><option value="">None</option><option>Gold</option><option>Silver</option><option>Platinum</option><option>Other</option></select></div><div><label class="form-label">Purity Standard</label><input class="form-control" name="purity_standard" placeholder="22K / 916"></div><div><label class="form-label">Min Purity %</label><input type="number" step="0.01" class="form-control" name="min_purity_percent"></div><div><label class="form-label">Max Purity %</label><input type="number" step="0.01" class="form-control" name="max_purity_percent"></div><div><label class="form-label">Max Loan %</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="max_loan_percent" value="70"></div><div><label class="form-label">Storage Fee %</label><input type="number" step="0.01" min="0" class="form-control" name="storage_fee_percent" value="0"></div><div><label class="form-label">Valuation</label><select class="form-select" name="valuation_method"><option>Weight</option><option>Piece</option><option>Stone</option><option>Combined</option></select></div><div class="span-2"><label class="form-label">Description</label><input class="form-control" name="description"></div></div>
                <div class="d-flex gap-3 mt-3"><label><input type="checkbox" name="requires_valuation" value="1" checked> Require valuation</label><label><input type="checkbox" name="requires_certificate" value="1"> Require certificate</label><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div><div class="d-flex gap-2 mt-3"><button class="btn-theme" type="submit">Save Category</button><button class="btn-soft form-reset" type="button" data-form="categoryForm">Clear</button></div>
            </form></div></div>
        </div>
    </section>

    <section class="tab-pane-x" data-pane="interest">
        <?php if(!$hasSchemes || !$hasSteps): ?><div class="page-card"><div class="card-body-x text-muted">Run the Pawn V2 migration first to enable dynamic interest schemes and escalation levels.</div></div><?php else: ?>
        <div class="settings-layout mb-3"><div class="page-card"><div class="page-head"><div class="section-title">Interest Schemes</div><span class="badge-soft"><?= count($schemes) ?> schemes</span></div><div class="table-responsive"><table class="table compact-table"><thead><tr><th>Code</th><th>Scheme</th><th>Tenure</th><th>Lock</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach($schemes as $row): ?><tr><td><?= e($row['scheme_code']) ?></td><td><strong><?= e($row['scheme_name']) ?></strong></td><td><?= e($row['tenure_type']==='At Closure'?'At Closure':$row['tenure_months'].' months') ?></td><td><?= !empty($row['permanent_escalation_until_closure'])?'Permanent':'No' ?></td><td class="<?= !empty($row['is_active'])?'status-on':'status-off' ?>"><?= !empty($row['is_active'])?'Active':'Inactive' ?></td><td><div class="mini-actions"><button class="mini-action edit-scheme" type="button" data-row='<?= e(json_encode($row)) ?>'>Edit</button><button class="mini-action toggle-record" type="button" data-target="pawn_interest_schemes" data-id="<?= (int)$row['id'] ?>" data-active="<?= !empty($row['is_active'])?0:1 ?>"><?= !empty($row['is_active'])?'Disable':'Enable' ?></button></div></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <div class="page-card sticky-form"><div class="page-head"><div class="section-title" id="schemeFormTitle">Add Interest Scheme</div></div><div class="card-body-x"><form id="schemeForm"><input type="hidden" name="action" value="save_scheme"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="id">
        <div class="form-grid"><div><label class="form-label">Scheme Code *</label><input class="form-control" name="scheme_code" required></div><div class="span-2"><label class="form-label">Scheme Name *</label><input class="form-control" name="scheme_name" required></div><div><label class="form-label">Tenure Type</label><select class="form-select" name="tenure_type" id="tenureType"><option>Fixed Months</option><option>At Closure</option></select></div><div><label class="form-label">Tenure Months</label><input type="number" min="1" class="form-control" name="tenure_months" value="12"></div><div><label class="form-label">Interest Method</label><select class="form-select" name="interest_method"><option>Simple</option><option>Reducing Balance</option><option>Flat</option></select></div><div><label class="form-label">Rounding</label><select class="form-select" name="interest_rounding_method"><option>Nearest Rupee</option><option>Ceil Rupee</option><option>Floor Rupee</option><option>None</option></select></div><div class="span-4"><label class="form-label">Description</label><textarea class="form-control" rows="2" name="description"></textarea></div></div><div class="d-flex gap-3 mt-3"><label><input type="checkbox" name="permanent_escalation_until_closure" value="1" checked> Escalated rate locked until closure</label><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div><div class="d-flex gap-2 mt-3"><button class="btn-theme">Save Scheme</button><button type="button" class="btn-soft form-reset" data-form="schemeForm">Clear</button></div></form></div></div></div>

        <div class="settings-layout"><div class="page-card"><div class="page-head"><div class="section-title">Rate Levels / Escalation Rules</div><span class="badge-soft"><?= count($steps) ?> levels</span></div><div class="table-responsive"><table class="table compact-table"><thead><tr><th>Scheme</th><th>Level</th><th>Rate</th><th>Due Cycle</th><th>Grace</th><th>After Miss</th><th>Next</th><th></th></tr></thead><tbody>
        <?php foreach($steps as $row): ?><tr><td><?= e($row['scheme_code']) ?></td><td>L<?= (int)$row['level_no'] ?></td><td><strong><?= number_format((float)$row['rate_percent'],3) ?>%</strong></td><td><?= e($row['interest_cycle_type']) ?> / <?= (int)$row['interest_cycle_value'] ?></td><td><?= (int)$row['grace_days'] ?> day(s)</td><td><?= (int)$row['missed_cycles_to_escalate'] ?> miss</td><td><?= $row['next_level_no']!==null?'L'.(int)$row['next_level_no']:'Final' ?></td><td><div class="mini-actions"><button type="button" class="mini-action edit-step" data-row='<?= e(json_encode($row)) ?>'>Edit</button><button type="button" class="mini-action toggle-record" data-target="pawn_interest_rate_steps" data-id="<?= (int)$row['id'] ?>" data-active="<?= !empty($row['is_active'])?0:1 ?>"><?= !empty($row['is_active'])?'Disable':'Enable' ?></button></div></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <div class="page-card sticky-form"><div class="page-head"><div class="section-title" id="stepFormTitle">Add Rate Level</div></div><div class="card-body-x"><form id="stepForm"><input type="hidden" name="action" value="save_step"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="id"><div class="form-grid"><div class="span-2"><label class="form-label">Scheme *</label><select class="form-select" name="scheme_id" required><option value="">Select Scheme</option><?php foreach($schemes as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['scheme_code'].' - '.$s['scheme_name']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Level *</label><input class="form-control" type="number" min="1" name="level_no" value="1" required></div><div><label class="form-label">Rate % *</label><input class="form-control" type="number" min="0" step="0.001" name="rate_percent" value="1.500" required></div><div><label class="form-label">Due Cycle Type</label><select class="form-select" name="interest_cycle_type"><option>Calendar Month</option><option>Days</option><option>Months</option></select></div><div><label class="form-label">Cycle Value</label><input class="form-control" type="number" min="1" name="interest_cycle_value" value="1"></div><div><label class="form-label">Grace Days</label><input class="form-control" type="number" min="0" name="grace_days" value="0"></div><div><label class="form-label">Misses to Escalate</label><input class="form-control" type="number" min="1" name="missed_cycles_to_escalate" value="1"></div><div><label class="form-label">Next Level</label><input class="form-control" type="number" min="1" name="next_level_no" placeholder="Blank = final"></div><div class="span-2"><label class="form-label">Escalation Effective</label><select class="form-select" name="escalation_effective"><option>Next Cycle</option><option>Immediately</option></select></div></div><div class="d-flex gap-3 mt-3"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div><div class="small text-muted mt-2">For your 1.5% rule use Calendar Month / 1. For your 2% rule use Days / 90.</div><div class="d-flex gap-2 mt-3"><button class="btn-theme">Save Rate Level</button><button type="button" class="btn-soft form-reset" data-form="stepForm">Clear</button></div></form></div></div></div>
        <?php endif; ?>
    </section>

    <section class="tab-pane-x" data-pane="banks">
        <?php if(!$hasBanks): ?><div class="page-card"><div class="card-body-x text-muted">Run the Pawn V2 migration first to enable Bank Master.</div></div><?php else: ?>
        <div class="settings-layout"><div class="page-card"><div class="page-head"><div class="section-title">Banks / Bank Branches</div><span class="badge-soft"><?= count($banks) ?> records</span></div><div class="table-responsive"><table class="table compact-table"><thead><tr><th>Code</th><th>Bank</th><th>Branch</th><th>Contact</th><th>Account</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach($banks as $row): ?><tr><td><?= e($row['bank_code']) ?></td><td><strong><?= e($row['bank_name']) ?></strong></td><td><?= e($row['branch_name'] ?: '—') ?></td><td><?= e($row['contact_person'] ?: '—') ?><div class="text-muted"><?= e($row['mobile'] ?: '') ?></div></td><td><?= e($row['account_number_masked'] ?: '—') ?></td><td class="<?= !empty($row['is_active'])?'status-on':'status-off' ?>"><?= !empty($row['is_active'])?'Active':'Inactive' ?></td><td><div class="mini-actions"><button type="button" class="mini-action edit-bank" data-row='<?= e(json_encode($row)) ?>'>Edit</button><button type="button" class="mini-action toggle-record" data-target="pawn_banks" data-id="<?= (int)$row['id'] ?>" data-active="<?= !empty($row['is_active'])?0:1 ?>"><?= !empty($row['is_active'])?'Disable':'Enable' ?></button></div></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <div class="page-card sticky-form"><div class="page-head"><div class="section-title" id="bankFormTitle">Add Bank</div></div><div class="card-body-x"><form id="bankForm"><input type="hidden" name="action" value="save_bank"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="id"><div class="form-grid"><div><label class="form-label">Bank Code *</label><input class="form-control" name="bank_code" required></div><div class="span-2"><label class="form-label">Bank Name *</label><input class="form-control" name="bank_name" required></div><div><label class="form-label">Branch</label><input class="form-control" name="branch_name"></div><div class="span-2"><label class="form-label">Branch Address</label><input class="form-control" name="branch_address"></div><div><label class="form-label">Contact Person</label><input class="form-control" name="contact_person"></div><div><label class="form-label">Mobile</label><input class="form-control" name="mobile"></div><div class="span-2"><label class="form-label">Account No. / Masked</label><input class="form-control" name="account_number_masked" placeholder="XXXX1234"></div><div class="span-4"><label class="form-label">Notes</label><textarea class="form-control" rows="2" name="notes"></textarea></div></div><div class="mt-3"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div><div class="d-flex gap-2 mt-3"><button class="btn-theme">Save Bank</button><button type="button" class="btn-soft form-reset" data-form="bankForm">Clear</button></div></form></div></div></div>
        <?php endif; ?>
    </section>

    <section class="tab-pane-x" data-pane="general">
        <div class="settings-layout"><div class="page-card"><div class="page-head"><div class="section-title">Business Rules</div></div><div class="card-body-x"><form id="generalForm"><input type="hidden" name="action" value="save_general"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="switch-row"><div><strong>Automatic Interest Escalation</strong><span>When a due cycle passes grace without full interest payment, move permanently to the configured next rate level.</span></div><input type="checkbox" name="pawn_auto_escalate_interest" value="1" <?= $general['pawn_auto_escalate_interest']==='1'?'checked':'' ?>></div>
        <div class="switch-row"><div><strong>Block Customer Gold Release if Bank-Pledged</strong><span>Do not allow physical customer release while an active bank collateral mapping still exists.</span></div><input type="checkbox" name="pawn_block_release_if_bank_pledged" value="1" <?= $general['pawn_block_release_if_bank_pledged']==='1'?'checked':'' ?>></div>
        <div class="switch-row"><div><strong>Allow Partial Principal Payment</strong><span>Permit principal reductions before final closure.</span></div><input type="checkbox" name="pawn_allow_partial_principal" value="1" <?= $general['pawn_allow_partial_principal']==='1'?'checked':'' ?>></div>
        <div class="switch-row"><div><strong>Require Customer ID Proof</strong><span>Require ID proof information during pawn registration.</span></div><input type="checkbox" name="pawn_require_id_proof" value="1" <?= $general['pawn_require_id_proof']==='1'?'checked':'' ?>></div>
        <div class="switch-row"><div><strong>Require Pawn Item Photo</strong><span>Require at least one item image for each newly registered pawn item.</span></div><input type="checkbox" name="pawn_require_item_photo" value="1" <?= $general['pawn_require_item_photo']==='1'?'checked':'' ?>></div>
        <div class="form-grid mt-3"><div><label class="form-label">Customer Interest Reminder Days</label><input class="form-control" type="number" min="0" name="pawn_interest_due_reminder_days" value="<?= e($general['pawn_interest_due_reminder_days']) ?>"></div><div><label class="form-label">Bank Interest Reminder Days</label><input class="form-control" type="number" min="0" name="pawn_bank_interest_reminder_days" value="<?= e($general['pawn_bank_interest_reminder_days']) ?>"></div><div><label class="form-label">Default Document Charge</label><input class="form-control" type="number" step="0.01" min="0" name="pawn_default_document_charge" value="<?= e($general['pawn_default_document_charge']) ?>"></div><div><label class="form-label">Default Other Charge</label><input class="form-control" type="number" step="0.01" min="0" name="pawn_default_other_charge" value="<?= e($general['pawn_default_other_charge']) ?>"></div></div>
        <button class="btn-theme mt-3">Save General Settings</button></form></div></div>
        <div class="page-card sticky-form"><div class="page-head"><div class="section-title">Rule Summary</div></div><div class="card-body-x"><div class="small text-muted">Interest percentage, due period, grace days, missed cycles and next rate are configured under <strong>Interest Rules</strong>. Do not hard-code 1.5%, 2%, 2.5%, 30/31 or 90-day values in Pawn Entry.</div><hr><div class="small"><strong>Customer side:</strong> rate escalation remains locked until the pawn is closed and re-registered.</div><hr><div class="small"><strong>Bank side:</strong> bank principal and bank interest are separate from customer principal and customer interest.</div></div></div></div>
    </section>

    <?php include('includes/footer.php'); ?>
</div></main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){'use strict';
const tabs=[...document.querySelectorAll('.settings-tab')], panes=[...document.querySelectorAll('.tab-pane-x')];
function toast(ok,msg){const d=document.createElement('div');d.className='toast-x '+(ok?'toast-ok':'toast-bad');d.textContent=msg;document.body.appendChild(d);setTimeout(()=>d.remove(),3200)}
async function postForm(form){const r=await fetch('api/pawn-settings.php',{method:'POST',body:new FormData(form),credentials:'same-origin'});let j={};try{j=await r.json()}catch(e){throw new Error('Invalid server response.')}if(!r.ok||!j.success)throw new Error(j.message||'Request failed');return j}
function activate(name){tabs.forEach(b=>b.classList.toggle('active',b.dataset.tab===name));panes.forEach(p=>p.classList.toggle('active',p.dataset.pane===name));try{localStorage.setItem('pawnSettingsTab',name)}catch(e){}}
tabs.forEach(b=>b.onclick=()=>activate(b.dataset.tab));
try{const saved=localStorage.getItem('pawnSettingsTab');if(saved&&document.querySelector('[data-pane="'+saved+'"]'))activate(saved)}catch(e){}
['categoryForm','schemeForm','stepForm','bankForm','generalForm'].forEach(id=>{const f=document.getElementById(id);if(!f)return;f.onsubmit=async e=>{e.preventDefault();const b=f.querySelector('button[type="submit"],button:not([type])');if(b)b.disabled=true;try{const j=await postForm(f);toast(true,j.message);setTimeout(()=>location.reload(),450)}catch(err){toast(false,err.message)}finally{if(b)b.disabled=false}}});
function fill(formId,row,titleId,title){const f=document.getElementById(formId);if(!f)return;Object.keys(row).forEach(k=>{const el=f.elements[k];if(!el)return;if(el.type==='checkbox')el.checked=String(row[k])==='1';else el.value=row[k]===null?'':row[k]});const t=document.getElementById(titleId);if(t)t.textContent=title;window.scrollTo({top:f.getBoundingClientRect().top+window.scrollY-90,behavior:'smooth'})}
document.querySelectorAll('.edit-category').forEach(b=>b.onclick=()=>fill('categoryForm',JSON.parse(b.dataset.row),'categoryFormTitle','Edit Category'));
document.querySelectorAll('.edit-scheme').forEach(b=>b.onclick=()=>fill('schemeForm',JSON.parse(b.dataset.row),'schemeFormTitle','Edit Interest Scheme'));
document.querySelectorAll('.edit-step').forEach(b=>b.onclick=()=>fill('stepForm',JSON.parse(b.dataset.row),'stepFormTitle','Edit Rate Level'));
document.querySelectorAll('.edit-bank').forEach(b=>b.onclick=()=>fill('bankForm',JSON.parse(b.dataset.row),'bankFormTitle','Edit Bank'));
document.querySelectorAll('.form-reset').forEach(b=>b.onclick=()=>{const f=document.getElementById(b.dataset.form);if(!f)return;f.reset();if(f.elements.id)f.elements.id.value='';if(f.id==='categoryForm')document.getElementById('categoryFormTitle').textContent='Add Category';if(f.id==='schemeForm')document.getElementById('schemeFormTitle').textContent='Add Interest Scheme';if(f.id==='stepForm')document.getElementById('stepFormTitle').textContent='Add Rate Level';if(f.id==='bankForm')document.getElementById('bankFormTitle').textContent='Add Bank'});
document.querySelectorAll('.toggle-record').forEach(b=>b.onclick=async()=>{if(!confirm((b.dataset.active==='1'?'Activate':'Deactivate')+' this record?'))return;const fd=new FormData();fd.append('action','toggle_active');fd.append('csrf_token',<?= json_encode($csrfToken) ?>);fd.append('target',b.dataset.target);fd.append('id',b.dataset.id);fd.append('active',b.dataset.active);try{const r=await fetch('api/pawn-settings.php',{method:'POST',body:fd,credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.success)throw new Error(j.message||'Unable to update');toast(true,j.message);setTimeout(()=>location.reload(),400)}catch(e){toast(false,e.message)}});
})();
</script>
</body></html>
