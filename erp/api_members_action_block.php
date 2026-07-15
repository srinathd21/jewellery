<?php
/*
 * Chit Close API - Members Preview Add-on
 * Add this block after:
 * $action = strtolower(trim((string)($_REQUEST['action'] ?? 'groups')));
 */

if ($action === 'members') {

    if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
        respond(false, 'You do not have permission to view chit members.', [], 403);
    }

    $groupId = (int)($_GET['group_id'] ?? 0);

    if ($groupId <= 0) {
        respond(false, 'Invalid chit group selected.', [], 422);
    }

    $stmt = $conn->prepare(
        "SELECT id
         FROM chit_groups
         WHERE id = ?
         AND business_id = ?
         AND branch_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        respond(false, 'Unable to validate chit group.', [], 500);
    }

    $stmt->bind_param("iii", $groupId, $businessId, $branchId);
    $stmt->execute();

    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group) {
        respond(false, 'Chit group not found.', [], 404);
    }

    $stmt = $conn->prepare(
        "SELECT
            cm.id,
            COALESCE(c.customer_name, cm.member_name) AS customer_name,
            COALESCE(c.mobile,'') AS mobile,
            cm.installment_amount,
            cm.status
         FROM chit_members cm
         LEFT JOIN customers c ON c.id = cm.customer_id
         WHERE cm.chit_group_id = ?
         AND cm.business_id = ?
         ORDER BY cm.id ASC"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare member query.', [], 500);
    }

    $stmt->bind_param("ii", $groupId, $businessId);
    $stmt->execute();

    $result = $stmt->get_result();
    $members = [];

    while ($row = $result->fetch_assoc()) {
        $members[] = [
            'id' => (int)$row['id'],
            'customer_name' => $row['customer_name'],
            'mobile' => $row['mobile'],
            'installment_amount' => number_format((float)$row['installment_amount'], 2, '.', ''),
            'status' => $row['status']
        ];
    }

    $stmt->close();

    respond(true, 'Members loaded successfully.', [
        'members' => $members,
        'count' => count($members)
    ]);
}
?>
