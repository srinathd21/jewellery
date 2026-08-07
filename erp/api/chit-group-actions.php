<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');
function out(bool $s, string $m, array $x = [], int $c = 200): void
{
    http_response_code($c);
    echo json_encode(array_merge(['success' => $s, 'message' => $m], $x));
    exit;
}
$root = dirname(__DIR__);
foreach ([$root . '/config/config.php', $root . '/config.php', $root . '/includes/config.php', $root . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    out(false, 'Database configuration is not available.', [], 500);
$conn->set_charset('utf8mb4');
function te(mysqli $c, string $t): bool
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
function pe(mysqli $c, string $a): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $m = ['update' => 'can_update', 'delete' => 'can_delete'];
    $f = $m[$a] ?? '';
    foreach (['perm.chit.groups', 'perm.chit'] as $p)
        if (isset($_SESSION['permissions'][$p][$f]))
            return (int) $_SESSION['permissions'][$p][$f] === 1;
    return in_array(strtolower((string) ($_SESSION['role_name'] ?? '')), ['admin', 'business admin', 'manager', 'billing'], true);
}
if (empty($_SESSION['user_id']))
    out(false, 'Session expired.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    out(false, 'Invalid request method.', [], 405);
if (!hash_equals((string) ($_SESSION['chit_groups_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    out(false, 'Invalid request token. Refresh the page.', [], 419);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int) $_SESSION['user_id'];
$id = (int) ($_POST['group_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
if ($id <= 0)
    out(false, 'Invalid chit group.', [], 422);
$conn->begin_transaction();
try {
    $s = $conn->prepare('SELECT * FROM chit_groups WHERE id=? AND business_id=? AND branch_id=? LIMIT 1 FOR UPDATE');
    $s->bind_param('iii', $id, $businessId, $branchId);
    $s->execute();
    $old = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$old)
        throw new RuntimeException('Chit group not found.');
    if ($action === 'update') {
        if (!pe($conn, 'update'))
            out(false, 'Update permission denied.', [], 403);
        $name = trim((string) ($_POST['group_name'] ?? ''));
        $type = (string) ($_POST['chit_type'] ?? 'Money');
        $start = (string) ($_POST['start_date'] ?? '');
        $members = (int) ($_POST['total_members'] ?? 0);
        $months = (int) ($_POST['total_months'] ?? 0);
        $installment = (float) ($_POST['installment_amount'] ?? 0);
        $value = (float) ($_POST['chit_value'] ?? 0);
        $auction = (string) ($_POST['auction_type'] ?? 'Auction');
        $auctionDay = trim((string) ($_POST['auction_day'] ?? ''));
        $auctionDay = $auctionDay === '' ? null : (int) $auctionDay;
        $grace = (int) ($_POST['grace_days'] ?? 0);
        $late = (float) ($_POST['late_fee_amount'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'Active');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($name === '' || $members < 1 || $months < 1)
            throw new RuntimeException('Group name, members and months are required.');
        if (!in_array($type, ['Money', 'Silver', 'Gold'], true) || !in_array($auction, ['Auction', 'Lucky Draw', 'Manual'], true) || !in_array($status, ['Draft', 'Active', 'Closed', 'Cancelled'], true))
            throw new RuntimeException('Invalid option selected.');
        $d = DateTime::createFromFormat('Y-m-d', $start);
        if (!$d || $d->format('Y-m-d') !== $start)
            throw new RuntimeException('Invalid start date.');
        $end = (clone $d)->modify('+' . ($months - 1) . ' months')->format('Y-m-d');
        $count = 0;
        $q = $conn->prepare('SELECT COUNT(*) c FROM chit_members WHERE chit_group_id=?');
        $q->bind_param('i', $id);
        $q->execute();
        $count = (int) ($q->get_result()->fetch_assoc()['c'] ?? 0);
        $q->close();
        if ($members < $count)
            throw new RuntimeException('Total members cannot be less than current joined members.');
        $s = $conn->prepare('UPDATE chit_groups SET group_name=?,chit_type=?,start_date=?,end_date=?,total_members=?,total_months=?,installment_amount=?,chit_value=?,auction_type=?,auction_day=?,grace_days=?,late_fee_amount=?,status=?,notes=? WHERE id=? AND business_id=? AND branch_id=?');
        $s->bind_param('ssssiiddsiidssiii', $name, $type, $start, $end, $members, $months, $installment, $value, $auction, $auctionDay, $grace, $late, $status, $notes, $id, $businessId, $branchId);
    }
    if ($action === 'delete') {
        if (!pe($conn, 'delete'))
            out(false, 'Delete permission denied.', [], 403);
        foreach (['chit_members', 'chit_collections', 'chit_prizes', 'chit_payouts'] as $t) {
            if (!te($conn, $t))
                continue;
            $col = $t === 'chit_members' ? 'chit_group_id' : ($t === 'chit_collections' ? 'chit_group_id' : ($t === 'chit_prizes' ? 'chit_group_id' : 'chit_prize_id'));
            if ($t === 'chit_payouts') {
                continue;
            }
            $q = $conn->prepare("SELECT COUNT(*) c FROM `$t` WHERE `$col`=?");
            $q->bind_param('i', $id);
            $q->execute();
            $c = (int) ($q->get_result()->fetch_assoc()['c'] ?? 0);
            $q->close();
            if ($c > 0)
                throw new RuntimeException('Cannot delete: linked ' . str_replace('_', ' ', $t) . ' records exist.');
        }
        $q = $conn->prepare('DELETE FROM chit_installments WHERE chit_group_id=?');
        $q->bind_param('i', $id);
        $q->execute();
        $q->close();
        $q = $conn->prepare('DELETE FROM chit_groups WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
        $q->bind_param('iii', $id, $businessId, $branchId);
        $q->execute();
        $q->close();
        $conn->commit();
        out(true, 'Chit group deleted successfully.');
    }
    if ($action !== 'update')
        throw new RuntimeException('Invalid action.');
    if (!$s)
        throw new RuntimeException('Unable to prepare update: ' . $conn->error);
    if (!$s->execute())
        throw new RuntimeException('Unable to update chit group: ' . $s->error);
    $s->close();
    // Rebuild installment schedule only when there are no collections against this group.
    $canRebuild = true;
    if (te($conn, 'chit_collections')) {
        $q = $conn->prepare('SELECT COUNT(*) c FROM chit_collections WHERE chit_group_id=?');
        if ($q) {
            $q->bind_param('i', $id);
            $q->execute();
            $canRebuild = (int) ($q->get_result()->fetch_assoc()['c'] ?? 0) === 0;
            $q->close();
        }
    }
    if ($canRebuild) {
        $q = $conn->prepare('DELETE FROM chit_installments WHERE chit_group_id=?');
        $q->bind_param('i', $id);
        $q->execute();
        $q->close();
        $q = $conn->prepare("INSERT INTO chit_installments (business_id,chit_group_id,installment_no,due_date,status) VALUES (?,?,?,?,'Open')");
        for ($n = 1; $n <= $months; $n++) {
            $due = (clone $d)->modify('+' . ($n - 1) . ' months')->format('Y-m-d');
            $q->bind_param('iiis', $businessId, $id, $n, $due);
            $q->execute();
        }
        $q->close();
    }
    $conn->commit();
    out(true, 'Chit group updated successfully.', ['group_id' => $id]);
} catch (Throwable $e) {
    $conn->rollback();
    out(false, $e->getMessage(), [], 500);
}
