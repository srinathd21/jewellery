<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
header('Content-Type: application/json; charset=utf-8');
function out(bool $ok, string $message = '', array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($conn) || !($conn instanceof mysqli))
    out(false, 'Database unavailable.', [], 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    out(false, 'Unauthenticated.', [], 401);
$token = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['billing_csrf']) || !hash_equals((string) $_SESSION['billing_csrf'], $token))
    out(false, 'Session expired.', [], 419);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');
if ($action === 'list_customer_chits') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId <= 0)
        out(true, '', ['chits' => []]);
    $sql = "SELECT cm.id chit_member_id,cm.chit_group_id,cm.ticket_no,cm.status member_status,cg.group_no,cg.group_name,cg.chit_type,cg.chit_value,cg.installment_amount,cg.status group_status,
        COALESCE((SELECT SUM(cc.paid_amount) FROM chit_collections cc WHERE cc.business_id=cm.business_id AND cc.chit_member_id=cm.id),0) paid_amount,
        COALESCE((SELECT SUM(scc.claim_amount) FROM sales_chit_claims scc WHERE scc.business_id=cm.business_id AND scc.chit_member_id=cm.id AND scc.status='Posted'),0) claimed_amount
        FROM chit_members cm INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id AND cg.business_id=cm.business_id
        WHERE cm.business_id=? AND cm.customer_id=? AND cm.status IN ('Active','Completed') AND cg.status IN ('Active','Closed') ORDER BY cg.start_date DESC,cm.id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        out(false, 'Run the sales_chit_claims migration first.', [], 500);
    $stmt->bind_param('ii', $businessId, $customerId);
    $stmt->execute();
    $r = $stmt->get_result();
    $rows = [];
    while ($x = $r->fetch_assoc()) {
        $x['available_amount'] = max(0, (float) $x['paid_amount'] - (float) $x['claimed_amount']);
        $rows[] = $x;
    }
    $stmt->close();
    out(true, '', ['chits' => $rows]);
}
if ($action === 'save_sale_claims') {
    $saleId = (int) ($_POST['sale_id'] ?? 0);
    $claims = json_decode((string) ($_POST['claims'] ?? '[]'), true);
    if ($saleId <= 0 || !is_array($claims))
        out(false, 'Invalid claim data.', [], 422);
    $saleStmt = $conn->prepare('SELECT id,customer_id,grand_total,paid_amount FROM sales WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
    $saleStmt->bind_param('iii', $saleId, $businessId, $branchId);
    $saleStmt->execute();
    $sale = $saleStmt->get_result()->fetch_assoc();
    $saleStmt->close();
    if (!$sale)
        out(false, 'Sale not found.', [], 404);
    $check = $conn->prepare("SELECT cm.id,cm.chit_group_id,COALESCE((SELECT SUM(cc.paid_amount) FROM chit_collections cc WHERE cc.business_id=cm.business_id AND cc.chit_member_id=cm.id),0)-COALESCE((SELECT SUM(scc.claim_amount) FROM sales_chit_claims scc WHERE scc.business_id=cm.business_id AND scc.chit_member_id=cm.id AND scc.status='Posted'),0) available FROM chit_members cm WHERE cm.id=? AND cm.business_id=? AND cm.customer_id=? LIMIT 1");
    $insert = $conn->prepare("INSERT INTO sales_chit_claims(business_id,branch_id,sale_id,customer_id,chit_group_id,chit_member_id,claim_amount,status,created_by) VALUES(?,?,?,?,?,?,?,'Posted',?)");
    if (!$check || !$insert)
        out(false, 'Run the sales_chit_claims migration first.', [], 500);
    $conn->begin_transaction();
    try {
        $total = 0.0;
        foreach ($claims as $c) {
            $memberId = (int) ($c['chit_member_id'] ?? 0);
            $amount = round((float) ($c['claim_amount'] ?? 0), 2);
            if ($amount <= 0)
                continue;
            $check->bind_param('iii', $memberId, $businessId, $sale['customer_id']);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            if (!$row)
                throw new RuntimeException('Invalid chit selection.');
            if ($amount > (float) $row['available'] + 0.001)
                throw new RuntimeException('Claim exceeds available chit amount.');
            $groupId = (int) $row['chit_group_id'];
            $insert->bind_param('iiiiiidi', $businessId, $branchId, $saleId, $sale['customer_id'], $groupId, $memberId, $amount, $userId);
            $insert->execute();
            $total += $amount;
        }
        if ($total > (float) $sale['grand_total'] + 0.001)
            throw new RuntimeException('Total chit claim exceeds sale total.');
        $netPayable = max(0, (float) $sale['grand_total'] - $total);
        $paid = min((float) $sale['paid_amount'], $netPayable);
        $balance = max(0, $netPayable - $paid);
        $paymentStatus = $balance <= 0.001 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid');
        $saleUpdate = $conn->prepare('UPDATE sales SET chit_claim_amount=?,net_payable_amount=?,paid_amount=?,balance_amount=?,payment_status=? WHERE id=? AND business_id=?');
        if (!$saleUpdate)
            throw new RuntimeException('Sales claim columns are missing. Run the migration.');
        $saleUpdate->bind_param('ddddsii', $total, $netPayable, $paid, $balance, $paymentStatus, $saleId, $businessId);
        $saleUpdate->execute();
        $saleUpdate->close();
        $conn->commit();
        $check->close();
        $insert->close();
        out(true, 'Chit claims saved.', ['claimed_amount' => $total, 'net_payable_amount' => $netPayable]);
    } catch (Throwable $e) {
        $conn->rollback();
        $check->close();
        $insert->close();
        out(false, $e->getMessage(), [], 422);
    }
}
out(false, 'Invalid action.', [], 400);
