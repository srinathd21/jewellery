<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function chitMemberPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    foreach (['perm.chit.members', 'perm.chit.groups', 'perm.chit'] as $permissionCode) {
        if (isset($_SESSION['permissions'][$permissionCode][$field])) {
            return (int)$_SESSION['permissions'][$permissionCode][$field] === 1;
        }
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
    $userType = strtolower(trim((string)($_SESSION['user_type'] ?? '')));

    $admins = ['platform admin', 'super admin', 'admin', 'business admin', 'manager', 'billing', 'super_admin', 'business_admin'];
    return in_array($roleName, $admins, true)
        || in_array($roleCode, $admins, true)
        || in_array($userType, $admins, true);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$requestedGroupId = (int)($_GET['group_id'] ?? $_GET['id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected.');
}

if ($requestedGroupId <= 0) {
    http_response_code(422);
    die('Invalid chit group ID.');
}

if (!chitMemberPermission($conn, 'open') || !chitMemberPermission($conn, 'create')) {
    http_response_code(403);
    die('Access denied. You do not have permission to add chit members.');
}

foreach (['chit_groups', 'chit_members', 'customers'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

$stmt = $conn->prepare(
    "SELECT cg.*,
            (SELECT COUNT(*) FROM chit_members cm WHERE cm.chit_group_id = cg.id) AS member_count
     FROM chit_groups cg
     WHERE cg.id = ?
       AND cg.business_id = ?
     LIMIT 1"
);
$stmt->bind_param('ii', $requestedGroupId, $businessId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    http_response_code(404);
    die('Chit group not found.');
}

$chitGroupId = (int)$group['id'];
$memberCount = (int)($group['member_count'] ?? 0);
$totalMembers = (int)($group['total_members'] ?? 0);
$remainingSlots = max(0, $totalMembers - $memberCount);

$customers = [];
if (tableExists($conn, 'customer_services')) {
    $sql = "SELECT DISTINCT c.id, c.customer_code, c.customer_name, c.mobile, c.email
            FROM customers c
            INNER JOIN customer_services cs
                ON cs.customer_id = c.id
               AND cs.business_id = c.business_id
               AND cs.service_type = 'Chit'
               AND cs.is_active = 1
            LEFT JOIN chit_members cm
                ON cm.customer_id = c.id
               AND cm.chit_group_id = ?
            WHERE c.business_id = ?
              AND c.is_active = 1
              AND cm.id IS NULL
            ORDER BY c.customer_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $chitGroupId, $businessId);
} else {
    $sql = "SELECT c.id, c.customer_code, c.customer_name, c.mobile, c.email
            FROM customers c
            LEFT JOIN chit_members cm
                ON cm.customer_id = c.id
               AND cm.chit_group_id = ?
            WHERE c.business_id = ?
              AND c.is_active = 1
              AND cm.id IS NULL
            ORDER BY c.customer_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $chitGroupId, $businessId);
}
$stmt->execute();
$result = $stmt->get_result();
while ($result && $row = $result->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

$nextTicketNo = 'T' . str_pad((string)($memberCount + 1), 3, '0', STR_PAD_LEFT);
if ($ticketStmt = $conn->prepare("SELECT ticket_no FROM chit_members WHERE chit_group_id = ? ORDER BY id DESC LIMIT 1")) {
    $ticketStmt->bind_param('i', $chitGroupId);
    $ticketStmt->execute();
    $lastTicket = $ticketStmt->get_result()->fetch_assoc();
    $ticketStmt->close();
    if ($lastTicket && preg_match('/(\d+)$/', (string)$lastTicket['ticket_no'], $match)) {
        $nextTicketNo = 'T' . str_pad((string)(((int)$match[1]) + 1), 3, '0', STR_PAD_LEFT);
    }
}

if (empty($_SESSION['chit_member_csrf'])) {
    $_SESSION['chit_member_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['chit_member_csrf'];

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
];

if (tableExists($conn, 'business_theme_settings')) {
    $themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
        $themeStmt->close();
        foreach ($theme as $key => $defaultValue) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$pageTitle = 'Add Chit Member';
$page_title = 'Add Chit Member';
$currentPage = 'chit-member-add';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Add Chit Member</title>
<?php include 'includes/links.php'; ?>
<style>
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text-color:<?php echo h($theme['text_color']); ?>;
    --muted-color:<?php echo h($theme['muted_text_color']); ?>;
    --border-color:<?php echo h($theme['border_color']); ?>;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{padding:12px 14px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:14px}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:10px}
.summary-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px}
.summary-label{font-size:8px;text-transform:uppercase;color:var(--muted-color);font-weight:700}.summary-value{font-size:14px;font-weight:800;margin-top:4px}
.form-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:10px}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:38px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 .2rem color-mix(in srgb,var(--primary) 18%,transparent)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.notice{padding:10px 12px;border-radius:9px;background:color-mix(in srgb,var(--primary) 10%,var(--card-bg));border:1px solid color-mix(in srgb,var(--primary) 25%,var(--border-color));font-size:10px;color:var(--text-color)}
.theme-toast{position:fixed;top:78px;right:18px;z-index:20000;display:flex;gap:9px;align-items:center;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;visibility:hidden;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;visibility:visible;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;color-scheme:dark}
.select2-container{width:100%!important}.select2-container .select2-selection--single{height:38px!important;border:1px solid var(--border-color)!important;border-radius:9px!important;background:var(--card-bg)!important}.select2-container .select2-selection--single .select2-selection__rendered{line-height:36px!important;color:var(--text-color)!important;font-size:10px;padding-left:11px}.select2-container .select2-selection--single .select2-selection__arrow{height:36px!important}.select2-dropdown{background:var(--card-bg)!important;border-color:var(--border-color)!important;color:var(--text-color)!important}.select2-results__option{font-size:10px}.select2-search__field{background:var(--card-bg)!important;color:var(--text-color)!important;border-color:var(--border-color)!important}
@media(max-width:991px){.summary-grid{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:repeat(2,1fr)}.span-3,.span-4,.span-6,.span-12{grid-column:span 1}.span-12{grid-column:1/-1}}
@media(max-width:767px){.summary-grid,.form-grid{grid-template-columns:1fr}.span-3,.span-4,.span-6,.span-12{grid-column:1}.page-heading{align-items:flex-start;flex-direction:column}.theme-toast{top:70px;left:12px;right:12px;min-width:0;max-width:none}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Add Chit Member</h1>
            <div class="page-subtitle"><?php echo h($group['group_no']); ?> · <?php echo h($group['group_name']); ?></div>
        </div>
        <a href="chit-view.php?id=<?php echo $chitGroupId; ?>" class="btn btn-light-custom">Back to Chit</a>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><div class="summary-label">Group</div><div class="summary-value"><?php echo h($group['group_no']); ?></div></div>
        <div class="summary-card"><div class="summary-label">Members</div><div class="summary-value"><?php echo $memberCount; ?> / <?php echo $totalMembers; ?></div></div>
        <div class="summary-card"><div class="summary-label">Available Slots</div><div class="summary-value"><?php echo $remainingSlots; ?></div></div>
        <div class="summary-card"><div class="summary-label">Installment</div><div class="summary-value">₹<?php echo number_format((float)$group['installment_amount'], 2); ?></div></div>
    </div>

    <form id="chitMemberForm" class="panel">
        <div class="panel-head">
            <div class="panel-title">Member Details</div>
            <div class="panel-subtitle">Select an existing Chit-enabled customer and assign a ticket number.</div>
        </div>
        <div class="panel-body">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="group_id" value="<?php echo $chitGroupId; ?>">

            <?php if ($remainingSlots <= 0): ?>
                <div class="notice mb-3">This chit group has reached its maximum member capacity.</div>
            <?php endif; ?>

            <div class="form-grid">
                <div class="span-6">
                    <label class="field-label">Customer <span class="text-danger">*</span></label>
                    <select name="customer_id" id="customer_id" class="form-select" required <?php echo $remainingSlots <= 0 ? 'disabled' : ''; ?>>
                        <option value="">Select customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo (int)$customer['id']; ?>">
                                <?php echo h($customer['customer_name']); ?>
                                <?php echo !empty($customer['customer_code']) ? ' · ' . h($customer['customer_code']) : ''; ?>
                                <?php echo !empty($customer['mobile']) ? ' · ' . h($customer['mobile']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="span-3">
                    <label class="field-label">Ticket Number <span class="text-danger">*</span></label>
                    <input type="text" name="ticket_no" class="form-control" value="<?php echo h($nextTicketNo); ?>" maxlength="50" required <?php echo $remainingSlots <= 0 ? 'disabled' : ''; ?>>
                </div>

                <div class="span-3">
                    <label class="field-label">Join Date <span class="text-danger">*</span></label>
                    <input type="date" name="join_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required <?php echo $remainingSlots <= 0 ? 'disabled' : ''; ?>>
                </div>

                <div class="span-4">
                    <label class="field-label">Nominee Name</label>
                    <input type="text" name="nominee_name" class="form-control" maxlength="150" <?php echo $remainingSlots <= 0 ? 'disabled' : ''; ?>>
                </div>

                <div class="span-4">
                    <label class="field-label">Nominee Relation</label>
                    <input type="text" name="nominee_relation" class="form-control" maxlength="100" <?php echo $remainingSlots <= 0 ? 'disabled' : ''; ?>>
                </div>

                <div class="span-4">
                    <label class="field-label">Member Status</label>
                    <select name="status" class="form-select" <?php echo $remainingSlots <= 0 ? 'disabled' : ''; ?>>
                        <option value="Active">Active</option>
                        <option value="Completed">Completed</option>
                        <option value="Defaulted">Defaulted</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-theme" id="saveMemberButton" <?php echo $remainingSlots <= 0 ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-user-plus me-1"></i>Add Member
                </button>
                <a href="chit-view.php?id=<?php echo $chitGroupId; ?>" class="btn btn-light-custom">Cancel</a>
            </div>
        </div>
    </form>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<div class="theme-toast" id="themeToast"><i class="fa-solid fa-circle-info"></i><span></span></div>
<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const toast = document.getElementById('themeToast');
    function showToast(message, success){
        if(!toast) return;
        toast.className = 'theme-toast ' + (success ? 'theme-toast-success' : 'theme-toast-error');
        toast.querySelector('span').textContent = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3500);
    }

    function initSelect2(){
        if(window.jQuery && jQuery.fn && jQuery.fn.select2){
            jQuery('#customer_id').select2({
                placeholder:'Select customer',
                allowClear:true,
                width:'100%'
            });
        }
    }

    document.addEventListener('DOMContentLoaded', initSelect2);

    const form = document.getElementById('chitMemberForm');
    form?.addEventListener('submit', async function(event){
        event.preventDefault();

        const button = document.getElementById('saveMemberButton');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        const formData = new FormData(form);
        formData.set('group_id', <?php echo json_encode($chitGroupId); ?>);

        try{
            const response = await fetch('api/chit-member-save.php', {
                method:'POST',
                body:formData,
                credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
            });

            const text = await response.text();
            let result;
            try{ result = JSON.parse(text); }
            catch(error){ throw new Error(text ? 'Invalid API response: ' + text.substring(0,160) : 'Empty API response.'); }

            if(!response.ok || !result.success){
                throw new Error(result.message || 'Unable to add chit member.');
            }

            showToast(result.message, true);
            setTimeout(() => {
                window.location.href = 'chit-view.php?id=' + encodeURIComponent(result.group_id) + '&msg=member_added';
            }, 650);
        }catch(error){
            showToast(error.message || 'Unable to add chit member.', false);
        }finally{
            button.disabled = false;
            button.innerHTML = original;
        }
    });
})();
</script>
</body>
</html>