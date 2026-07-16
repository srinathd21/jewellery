<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');
function out(bool $s, string $m, array $x = [], int $c = 200): void
{
    http_response_code($c);
    echo json_encode(array_merge(['success' => $s, 'message' => $m], $x), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    out(false, 'Session expired.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    out(false, 'Invalid request method.', [], 405);
if (!hash_equals((string) ($_SESSION['chit_collection_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    out(false, 'Invalid or expired request token.', [], 419);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$memberId = (int) ($_POST['member_id'] ?? 0);
$groupId = (int) ($_POST['group_id'] ?? 0);
$installmentId = (int) ($_POST['installment_id'] ?? 0);
$date = trim((string) ($_POST['collection_date'] ?? ''));
$due = max(0, (float) ($_POST['due_amount'] ?? 0));
$paid = max(0, (float) ($_POST['paid_amount'] ?? 0));
$disc = max(0, (float) ($_POST['discount_amount'] ?? 0));
$pen = max(0, (float) ($_POST['penalty_amount'] ?? 0));
$net = max(0, (float) ($_POST['net_amount'] ?? 0));
$method = (int) ($_POST['payment_method_id'] ?? 0);
$method = $method > 0 ? $method : null;
$ref = trim((string) ($_POST['reference_no'] ?? ''));
$remarks = trim((string) ($_POST['remarks'] ?? ''));
$receiver = trim((string) ($_POST['collection_receiver_name'] ?? ''));
if ($businessId <= 0 || $branchId <= 0 || $memberId <= 0 || $groupId <= 0 || $installmentId <= 0)
    out(false, 'Required collection details are missing.', [], 422);
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date)
    out(false, 'Valid collection date is required.', [], 422);
$expected = $paid + $pen - $disc;
if (abs($expected - $net) > 0.01)
    $net = max(0, $expected);
if ($net <= 0)
    out(false, 'Net received amount must be greater than zero.', [], 422);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT cm.id,cm.customer_id,cm.status member_status,cg.id group_id,cg.status group_status,cg.installment_amount FROM chit_members cm INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id WHERE cm.id=? AND cm.chit_group_id=? AND cm.business_id=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('iii', $memberId, $groupId, $businessId);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$m)
        throw new RuntimeException('Chit member not found.');
    if ($m['member_status'] !== 'Active' || in_array($m['group_status'], ['Closed', 'Cancelled'], true))
        throw new RuntimeException('Collection is not allowed for this member or group.');
    $stmt = $conn->prepare("SELECT id,installment_no,due_date FROM chit_installments WHERE id=? AND chit_group_id=? AND business_id=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('iii', $installmentId, $groupId, $businessId);
    $stmt->execute();
    $inst = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$inst)
        throw new RuntimeException('Installment not found.');
    $stmt = $conn->prepare("SELECT id FROM chit_collections WHERE chit_member_id=? AND chit_installment_id=? LIMIT 1");
    $stmt->bind_param('ii', $memberId, $installmentId);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($dup)
        throw new RuntimeException('Payment for this installment is already collected.');
    $prefix = 'CHR' . date('Ym', strtotime($date));
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT receipt_no FROM chit_collections WHERE business_id=? AND receipt_no LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->bind_param('is', $businessId, $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $seq = 1;
    if ($last && preg_match('/(\d{4})$/', (string) $last['receipt_no'], $mm))
        $seq = (int) $mm[1] + 1;
    $receipt = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("INSERT INTO chit_collections (business_id,branch_id,chit_group_id,chit_member_id,chit_installment_id,receipt_no,collection_date,due_amount,paid_amount,discount_amount,penalty_amount,net_amount,payment_method_id,reference_no,remarks,collection_receiver_name,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt)
        throw new RuntimeException('Unable to prepare collection insert: ' . $conn->error);
    $stmt->bind_param('iiiiissdddddisssi', $businessId, $branchId, $groupId, $memberId, $installmentId, $receipt, $date, $due, $paid, $disc, $pen, $net, $method, $ref, $remarks, $receiver, $userId);
    if (!$stmt->execute())
        throw new RuntimeException('Unable to save collection: ' . $stmt->error);
    $collectionId = (int) $stmt->insert_id;
    $stmt->close();
    $stmt = $conn->prepare("SELECT COUNT(*) total FROM chit_members WHERE chit_group_id=? AND status='Active'");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $active = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $stmt = $conn->prepare("SELECT COUNT(*) total FROM chit_collections WHERE chit_group_id=? AND chit_installment_id=?");
    $stmt->bind_param('ii', $groupId, $installmentId);
    $stmt->execute();
    $paidCount = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    if ($active > 0 && $paidCount >= $active) {
        $stmt = $conn->prepare("UPDATE chit_installments SET status='Closed' WHERE id=? AND chit_group_id=?");
        $stmt->bind_param('ii', $installmentId, $groupId);
        $stmt->execute();
        $stmt->close();
    }
    $conn->commit();
    out(true, 'Payment collected successfully.', ['collection_id' => $collectionId, 'receipt_no' => $receipt]);
} catch (Throwable $e) {
    $conn->rollback();
    out(false, $e->getMessage(), [], 500);
}
