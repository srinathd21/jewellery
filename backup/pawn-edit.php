<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

$pageTitle = 'Edit Pawn Entry';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

/* -------------------------------------------------------
   ROLE CHECK
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));
if (!in_array($roleName, ['admin', 'manager'], true)) {
    die('Access denied. Only Admin and Manager can edit pawn entries.');
}

/* -------------------------------------------------------
   GET PAWN ID
------------------------------------------------------- */
$pawnId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pawnId <= 0) {
    header('Location: pawn-list.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
if (!tableExists($conn, 'pawn_entries')) {
    die("Required table 'pawn_entries' not found.");
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$pawnHasBusinessId      = hasColumn($conn, 'pawn_entries', 'business_id');
$pawnHasCustomerId      = hasColumn($conn, 'pawn_entries', 'customer_id');
$pawnHasCustomerName    = hasColumn($conn, 'pawn_entries', 'customer_name');
$pawnHasCustomerMobile  = hasColumn($conn, 'pawn_entries', 'customer_mobile');
$pawnHasAddress         = hasColumn($conn, 'pawn_entries', 'address');
$pawnHasIdProofType     = hasColumn($conn, 'pawn_entries', 'id_proof_type');
$pawnHasIdProofNumber   = hasColumn($conn, 'pawn_entries', 'id_proof_number');
$pawnHasMetalType       = hasColumn($conn, 'pawn_entries', 'metal_type');
$pawnHasLoanType        = hasColumn($conn, 'pawn_entries', 'loan_type');
$pawnHasItemCount       = hasColumn($conn, 'pawn_entries', 'item_count');
$pawnHasTotalGrossWeight = hasColumn($conn, 'pawn_entries', 'total_gross_weight');
$pawnHasTotalLessWeight = hasColumn($conn, 'pawn_entries', 'total_less_weight');
$pawnHasTotalNetWeight  = hasColumn($conn, 'pawn_entries', 'total_net_weight');
$pawnHasLoanAmount      = hasColumn($conn, 'pawn_entries', 'loan_amount');
$pawnHasPrincipalBalance = hasColumn($conn, 'pawn_entries', 'principal_balance');
$pawnHasInterestRate    = hasColumn($conn, 'pawn_entries', 'interest_rate');
$pawnHasInterestType    = hasColumn($conn, 'pawn_entries', 'interest_type');
$pawnHasInterestMethod  = hasColumn($conn, 'pawn_entries', 'interest_method');
$pawnHasTenureMonths    = hasColumn($conn, 'pawn_entries', 'tenure_months');
$pawnHasMaturityDate    = hasColumn($conn, 'pawn_entries', 'maturity_date');
$pawnHasTicketCharge    = hasColumn($conn, 'pawn_entries', 'ticket_charge');
$pawnHasOtherCharge     = hasColumn($conn, 'pawn_entries', 'other_charge');
$pawnHasPaymentMethodId = hasColumn($conn, 'pawn_entries', 'payment_method_id');
$pawnHasPaymentReference = hasColumn($conn, 'pawn_entries', 'payment_reference');
$pawnHasRemarks         = hasColumn($conn, 'pawn_entries', 'remarks');
$pawnHasStatus          = hasColumn($conn, 'pawn_entries', 'status');
$pawnHasUpdatedAt       = hasColumn($conn, 'pawn_entries', 'updated_at');

/* -------------------------------------------------------
   FETCH PAWN DATA
------------------------------------------------------- */
$pawnData = null;
$sql = "SELECT * FROM pawn_entries WHERE id = ?";
if ($pawnHasBusinessId) {
    $sql .= " AND business_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $pawnId, $businessId);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $pawnId);
}

$stmt->execute();
$res = $stmt->get_result();
$pawnData = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$pawnData) {
    die('Pawn entry not found.');
}

// Check if pawn can be edited (only Active status)
if (($pawnData['status'] ?? '') !== 'Active') {
    die('This pawn entry cannot be edited as it is already ' . ($pawnData['status'] ?? 'Closed') . '.');
}

/* -------------------------------------------------------
   FETCH PAWN ITEMS
------------------------------------------------------- */
$pawnItems = [];
$itemHasPawnId = hasColumn($conn, 'pawn_items', 'pawn_id');
if ($itemHasPawnId && tableExists($conn, 'pawn_items')) {
    $itemsStmt = $conn->prepare("SELECT * FROM pawn_items WHERE pawn_id = ? ORDER BY id");
    $itemsStmt->bind_param('i', $pawnId);
    $itemsStmt->execute();
    $itemsRes = $itemsStmt->get_result();
    while ($row = $itemsRes->fetch_assoc()) {
        $pawnItems[] = $row;
    }
    $itemsStmt->close();
}

/* -------------------------------------------------------
   GET CUSTOMERS FOR SELECTION
------------------------------------------------------- */
$customers = [];
$custHasBusinessId = hasColumn($conn, 'customers', 'business_id');
$custHasCustomerName = hasColumn($conn, 'customers', 'customer_name');
$custHasCustomerCode = hasColumn($conn, 'customers', 'customer_code');
$custHasMobile = hasColumn($conn, 'customers', 'mobile');

$custQuery = "SELECT id";
if ($custHasCustomerName) $custQuery .= ", customer_name";
if ($custHasCustomerCode) $custQuery .= ", customer_code";
if ($custHasMobile) $custQuery .= ", mobile";
$custQuery .= " FROM customers WHERE 1=1";
if ($custHasBusinessId) $custQuery .= " AND business_id = " . (int)$businessId;
$custQuery .= " AND is_active = 1 ORDER BY customer_name ASC";

$custResult = $conn->query($custQuery);
if ($custResult) {
    while ($row = $custResult->fetch_assoc()) {
        $customers[] = $row;
    }
}

/* -------------------------------------------------------
   GET PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];
$pmtTableExists = tableExists($conn, 'payment_methods');
if ($pmtTableExists) {
    $pmtResult = $conn->query("SELECT id, method_name FROM payment_methods WHERE is_active = 1 ORDER BY method_name");
    if ($pmtResult) {
        while ($row = $pmtResult->fetch_assoc()) {
            $paymentMethods[] = $row;
        }
    }
}

/* -------------------------------------------------------
   PROCESS FORM SUBMISSION
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pawn'])) {
    // Get basic pawn entry data
    $entryDate = trim($_POST['entry_date'] ?? $pawnData['entry_date']);
    $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerMobile = trim($_POST['customer_mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $idProofType = trim($_POST['id_proof_type'] ?? '');
    $idProofNumber = trim($_POST['id_proof_number'] ?? '');
    $metalType = trim($_POST['metal_type'] ?? 'Gold');
    $loanType = trim($_POST['loan_type'] ?? 'General');
    $interestRate = (float)($_POST['interest_rate'] ?? 0);
    $interestType = trim($_POST['interest_type'] ?? 'Monthly');
    $interestMethod = trim($_POST['interest_method'] ?? 'Simple');
    $tenureMonths = (int)($_POST['tenure_months'] ?? 0);
    $ticketCharge = (float)($_POST['ticket_charge'] ?? 0);
    $otherCharge = (float)($_POST['other_charge'] ?? 0);
    $paymentMethodId = !empty($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : null;
    $paymentReference = trim($_POST['payment_reference'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    
    // Item details from form
    $itemIds = $_POST['item_id'] ?? [];
    $itemNames = $_POST['item_name'] ?? [];
    $itemCategories = $_POST['item_category'] ?? [];
    $itemPurifies = $_POST['item_purity'] ?? [];
    $itemGrossWeights = $_POST['item_gross_weight'] ?? [];
    $itemLessWeights = $_POST['item_less_weight'] ?? [];
    $itemStoneWeights = $_POST['item_stone_weight'] ?? [];
    $itemStoneAmounts = $_POST['item_stone_amount'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];
    
    // Calculate totals
    $itemCount = count($itemNames);
    $totalGrossWeight = 0;
    $totalLessWeight = 0;
    $totalNetWeight = 0;
    $totalEstimatedAmount = 0;
    
    $itemsData = [];
    for ($i = 0; $i < $itemCount; $i++) {
        $grossWeight = (float)($itemGrossWeights[$i] ?? 0);
        $lessWeight = (float)($itemLessWeights[$i] ?? 0);
        $netWeight = $grossWeight - $lessWeight;
        $stoneAmount = (float)($itemStoneAmounts[$i] ?? 0);
        
        $totalGrossWeight += $grossWeight;
        $totalLessWeight += $lessWeight;
        $totalNetWeight += $netWeight;
        
        $estimatedAmount = $netWeight * $interestRate + $stoneAmount;
        $totalEstimatedAmount += $estimatedAmount;
        
        $itemsData[] = [
            'id' => isset($itemIds[$i]) && !empty($itemIds[$i]) ? (int)$itemIds[$i] : 0,
            'item_name' => trim($itemNames[$i] ?? ''),
            'item_category' => trim($itemCategories[$i] ?? ''),
            'purity' => trim($itemPurifies[$i] ?? ''),
            'gross_weight' => $grossWeight,
            'less_weight' => $lessWeight,
            'net_weight' => $netWeight,
            'stone_weight' => (float)($itemStoneWeights[$i] ?? 0),
            'stone_amount' => $stoneAmount,
            'estimated_amount' => $estimatedAmount,
            'remarks' => trim($itemRemarks[$i] ?? '')
        ];
    }
    
    $loanAmount = (float)($_POST['loan_amount'] ?? $totalEstimatedAmount);
    $principalBalance = $loanAmount;
    
    // Calculate maturity date
    $maturityDate = null;
    if ($tenureMonths > 0) {
        $maturityDate = date('Y-m-d', strtotime("+{$tenureMonths} months", strtotime($entryDate)));
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Update pawn entry
        $updateSql = "UPDATE pawn_entries SET ";
        $updateFields = [];
        $params = [];
        $types = '';
        
        $updateFields[] = "entry_date = ?";
        $params[] = $entryDate;
        $types .= 's';
        
        if ($pawnHasCustomerId) {
            $updateFields[] = "customer_id = ?";
            $params[] = $customerId;
            $types .= 'i';
        }
        if ($pawnHasCustomerName) {
            $updateFields[] = "customer_name = ?";
            $params[] = $customerName;
            $types .= 's';
        }
        if ($pawnHasCustomerMobile) {
            $updateFields[] = "customer_mobile = ?";
            $params[] = $customerMobile;
            $types .= 's';
        }
        if ($pawnHasAddress) {
            $updateFields[] = "address = ?";
            $params[] = $address;
            $types .= 's';
        }
        if ($pawnHasIdProofType) {
            $updateFields[] = "id_proof_type = ?";
            $params[] = $idProofType;
            $types .= 's';
        }
        if ($pawnHasIdProofNumber) {
            $updateFields[] = "id_proof_number = ?";
            $params[] = $idProofNumber;
            $types .= 's';
        }
        if ($pawnHasMetalType) {
            $updateFields[] = "metal_type = ?";
            $params[] = $metalType;
            $types .= 's';
        }
        if ($pawnHasLoanType) {
            $updateFields[] = "loan_type = ?";
            $params[] = $loanType;
            $types .= 's';
        }
        if ($pawnHasItemCount) {
            $updateFields[] = "item_count = ?";
            $params[] = $itemCount;
            $types .= 'i';
        }
        if ($pawnHasTotalGrossWeight) {
            $updateFields[] = "total_gross_weight = ?";
            $params[] = $totalGrossWeight;
            $types .= 'd';
        }
        if ($pawnHasTotalLessWeight) {
            $updateFields[] = "total_less_weight = ?";
            $params[] = $totalLessWeight;
            $types .= 'd';
        }
        if ($pawnHasTotalNetWeight) {
            $updateFields[] = "total_net_weight = ?";
            $params[] = $totalNetWeight;
            $types .= 'd';
        }
        if ($pawnHasLoanAmount) {
            $updateFields[] = "loan_amount = ?";
            $params[] = $loanAmount;
            $types .= 'd';
        }
        if ($pawnHasPrincipalBalance) {
            $updateFields[] = "principal_balance = ?";
            $params[] = $principalBalance;
            $types .= 'd';
        }
        if ($pawnHasInterestRate) {
            $updateFields[] = "interest_rate = ?";
            $params[] = $interestRate;
            $types .= 'd';
        }
        if ($pawnHasInterestType) {
            $updateFields[] = "interest_type = ?";
            $params[] = $interestType;
            $types .= 's';
        }
        if ($pawnHasInterestMethod) {
            $updateFields[] = "interest_method = ?";
            $params[] = $interestMethod;
            $types .= 's';
        }
        if ($pawnHasTenureMonths) {
            $updateFields[] = "tenure_months = ?";
            $params[] = $tenureMonths;
            $types .= 'i';
        }
        if ($pawnHasMaturityDate) {
            $updateFields[] = "maturity_date = ?";
            $params[] = $maturityDate;
            $types .= 's';
        }
        if ($pawnHasTicketCharge) {
            $updateFields[] = "ticket_charge = ?";
            $params[] = $ticketCharge;
            $types .= 'd';
        }
        if ($pawnHasOtherCharge) {
            $updateFields[] = "other_charge = ?";
            $params[] = $otherCharge;
            $types .= 'd';
        }
        if ($pawnHasPaymentMethodId) {
            $updateFields[] = "payment_method_id = ?";
            $params[] = $paymentMethodId;
            $types .= 'i';
        }
        if ($pawnHasPaymentReference) {
            $updateFields[] = "payment_reference = ?";
            $params[] = $paymentReference;
            $types .= 's';
        }
        if ($pawnHasRemarks) {
            $updateFields[] = "remarks = ?";
            $params[] = $remarks;
            $types .= 's';
        }
        if ($pawnHasUpdatedAt) {
            $updateFields[] = "updated_at = NOW()";
        }
        
        $updateSql .= implode(", ", $updateFields);
        $updateSql .= " WHERE id = ?";
        $params[] = $pawnId;
        $types .= 'i';
        
        if ($pawnHasBusinessId) {
            $updateSql .= " AND business_id = ?";
            $params[] = $businessId;
            $types .= 'i';
        }
        
        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            throw new Exception("Failed to prepare update query: " . $conn->error);
        }
        
        $bindValues = [$types];
        for ($i = 0; $i < count($params); $i++) {
            $bindValues[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update pawn entry: " . $stmt->error);
        }
        $stmt->close();
        
        // Delete existing items and re-insert
        if ($itemHasPawnId && tableExists($conn, 'pawn_items')) {
            $deleteStmt = $conn->prepare("DELETE FROM pawn_items WHERE pawn_id = ?");
            $deleteStmt->bind_param('i', $pawnId);
            $deleteStmt->execute();
            $deleteStmt->close();
            
            // Insert new items
            $itemHasBusinessId = hasColumn($conn, 'pawn_items', 'business_id');
            $itemHasItemName = hasColumn($conn, 'pawn_items', 'item_name');
            $itemHasGrossWeight = hasColumn($conn, 'pawn_items', 'gross_weight');
            $itemHasNetWeight = hasColumn($conn, 'pawn_items', 'net_weight');
            
            if ($itemHasItemName && $itemHasGrossWeight) {
                foreach ($itemsData as $item) {
                    if (empty($item['item_name'])) continue;
                    
                    $itemSql = "INSERT INTO pawn_items (";
                    $itemSql .= $itemHasBusinessId ? "business_id, " : "";
                    $itemSql .= "pawn_id, item_name, ";
                    
                    if (hasColumn($conn, 'pawn_items', 'item_category')) $itemSql .= "item_category, ";
                    if (hasColumn($conn, 'pawn_items', 'purity')) $itemSql .= "purity, ";
                    if (hasColumn($conn, 'pawn_items', 'gross_weight')) $itemSql .= "gross_weight, ";
                    if (hasColumn($conn, 'pawn_items', 'less_weight')) $itemSql .= "less_weight, ";
                    if (hasColumn($conn, 'pawn_items', 'net_weight')) $itemSql .= "net_weight, ";
                    if (hasColumn($conn, 'pawn_items', 'stone_weight')) $itemSql .= "stone_weight, ";
                    if (hasColumn($conn, 'pawn_items', 'stone_amount')) $itemSql .= "stone_amount, ";
                    if (hasColumn($conn, 'pawn_items', 'estimated_amount')) $itemSql .= "estimated_amount, ";
                    if (hasColumn($conn, 'pawn_items', 'remarks')) $itemSql .= "remarks ";
                    
                    $itemSql = rtrim($itemSql, ", ");
                    $itemSql .= ") VALUES (";
                    
                    $itemParams = [];
                    $itemTypes = '';
                    
                    if ($itemHasBusinessId) {
                        $itemSql .= "?, ";
                        $itemParams[] = $businessId;
                        $itemTypes .= 'i';
                    }
                    
                    $itemSql .= "?, ?";
                    $itemParams[] = $pawnId;
                    $itemParams[] = $item['item_name'];
                    $itemTypes .= 'is';
                    
                    if (hasColumn($conn, 'pawn_items', 'item_category')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['item_category'];
                        $itemTypes .= 's';
                    }
                    if (hasColumn($conn, 'pawn_items', 'purity')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['purity'];
                        $itemTypes .= 's';
                    }
                    if (hasColumn($conn, 'pawn_items', 'gross_weight')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['gross_weight'];
                        $itemTypes .= 'd';
                    }
                    if (hasColumn($conn, 'pawn_items', 'less_weight')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['less_weight'];
                        $itemTypes .= 'd';
                    }
                    if (hasColumn($conn, 'pawn_items', 'net_weight')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['net_weight'];
                        $itemTypes .= 'd';
                    }
                    if (hasColumn($conn, 'pawn_items', 'stone_weight')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['stone_weight'];
                        $itemTypes .= 'd';
                    }
                    if (hasColumn($conn, 'pawn_items', 'stone_amount')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['stone_amount'];
                        $itemTypes .= 'd';
                    }
                    if (hasColumn($conn, 'pawn_items', 'estimated_amount')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['estimated_amount'];
                        $itemTypes .= 'd';
                    }
                    if (hasColumn($conn, 'pawn_items', 'remarks')) {
                        $itemSql .= ", ?";
                        $itemParams[] = $item['remarks'];
                        $itemTypes .= 's';
                    }
                    
                    $itemSql .= ")";
                    
                    $itemStmt = $conn->prepare($itemSql);
                    if (!$itemStmt) {
                        throw new Exception("Failed to prepare pawn item insert: " . $conn->error);
                    }
                    
                    $bindItemValues = [$itemTypes];
                    for ($i = 0; $i < count($itemParams); $i++) {
                        $bindItemValues[] = &$itemParams[$i];
                    }
                    call_user_func_array([$itemStmt, 'bind_param'], $bindItemValues);
                    
                    if (!$itemStmt->execute()) {
                        throw new Exception("Failed to insert pawn item: " . $itemStmt->error);
                    }
                    $itemStmt->close();
                }
            }
        }
        
        $conn->commit();
        
        // Audit log
        if (function_exists('addAuditLog')) {
            addAuditLog($conn, $businessId, $userId, 'Pawn Entry', 'Update', $pawnId, 'Updated pawn entry');
        }
        
        $success = "Pawn entry updated successfully!";
        
        // Refresh data
        $refreshStmt = $conn->prepare("SELECT * FROM pawn_entries WHERE id = ?");
        $refreshStmt->bind_param('i', $pawnId);
        $refreshStmt->execute();
        $refreshRes = $refreshStmt->get_result();
        $pawnData = $refreshRes->fetch_assoc();
        $refreshStmt->close();
        
        // Refresh items
        $pawnItems = [];
        $itemsStmt = $conn->prepare("SELECT * FROM pawn_items WHERE pawn_id = ? ORDER BY id");
        $itemsStmt->bind_param('i', $pawnId);
        $itemsStmt->execute();
        $itemsRes = $itemsStmt->get_result();
        while ($row = $itemsRes->fetch_assoc()) {
            $pawnItems[] = $row;
        }
        $itemsStmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0">Edit Pawn Entry - <?php echo h($pawnData['pawn_no']); ?></h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="pawn-list.php">Pawn List</a></li>
                                    <li class="breadcrumb-item active">Edit Pawn</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="pawnEntryForm">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Pawn Entry Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Pawn No</label>
                                        <input type="text" class="form-control" value="<?php echo h($pawnData['pawn_no']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Entry Date <span class="text-danger">*</span></label>
                                        <input type="date" name="entry_date" class="form-control" value="<?php echo $pawnData['entry_date']; ?>" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Metal Type <span class="text-danger">*</span></label>
                                        <select name="metal_type" class="form-select" required>
                                            <option value="Gold" <?php echo ($pawnData['metal_type'] ?? 'Gold') == 'Gold' ? 'selected' : ''; ?>>Gold</option>
                                            <option value="Silver" <?php echo ($pawnData['metal_type'] ?? '') == 'Silver' ? 'selected' : ''; ?>>Silver</option>
                                            <option value="Other" <?php echo ($pawnData['metal_type'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Loan Type</label>
                                        <input type="text" name="loan_type" class="form-control" value="<?php echo h($pawnData['loan_type'] ?? 'General'); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Interest Rate (%) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="interest_rate" id="interest_rate" class="form-control" value="<?php echo $pawnData['interest_rate'] ?? 0; ?>" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Interest Type</label>
                                        <select name="interest_type" class="form-select">
                                            <option value="Monthly" <?php echo ($pawnData['interest_type'] ?? 'Monthly') == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                                            <option value="Weekly" <?php echo ($pawnData['interest_type'] ?? '') == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                                            <option value="Daily" <?php echo ($pawnData['interest_type'] ?? '') == 'Daily' ? 'selected' : ''; ?>>Daily</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Interest Method</label>
                                        <select name="interest_method" class="form-select">
                                            <option value="Simple" <?php echo ($pawnData['interest_method'] ?? 'Simple') == 'Simple' ? 'selected' : ''; ?>>Simple</option>
                                            <option value="Flat" <?php echo ($pawnData['interest_method'] ?? '') == 'Flat' ? 'selected' : ''; ?>>Flat</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Tenure (Months)</label>
                                        <input type="number" name="tenure_months" id="tenure_months" class="form-control" value="<?php echo (int)($pawnData['tenure_months'] ?? 0); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Maturity Date</label>
                                        <input type="date" name="maturity_date" id="maturity_date" class="form-control" value="<?php echo $pawnData['maturity_date'] ?? ''; ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Ticket Charge</label>
                                        <input type="number" step="0.01" name="ticket_charge" id="ticket_charge" class="form-control" value="<?php echo $pawnData['ticket_charge'] ?? 0; ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Other Charge</label>
                                        <input type="number" step="0.01" name="other_charge" id="other_charge" class="form-control" value="<?php echo $pawnData['other_charge'] ?? 0; ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method_id" class="form-select">
                                            <option value="">Select Payment Method</option>
                                            <?php foreach ($paymentMethods as $pm): ?>
                                                <option value="<?php echo $pm['id']; ?>" <?php echo ($pawnData['payment_method_id'] ?? '') == $pm['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($pm['method_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Payment Reference</label>
                                        <input type="text" name="payment_reference" class="form-control" value="<?php echo h($pawnData['payment_reference'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Customer Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Select Existing Customer</label>
                                        <select name="customer_id" id="customer_id" class="form-select">
                                            <option value="">-- New Customer --</option>
                                            <?php foreach ($customers as $cust): ?>
                                                <option value="<?php echo $cust['id']; ?>"
                                                    data-name="<?php echo h($cust['customer_name'] ?? ''); ?>"
                                                    data-mobile="<?php echo h($cust['mobile'] ?? ''); ?>"
                                                    <?php echo ($pawnData['customer_id'] ?? '') == $cust['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($cust['customer_name'] ?? '') . ' (' . h($cust['customer_code'] ?? '') . ')'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                        <input type="text" name="customer_name" id="customer_name" class="form-control" value="<?php echo h($pawnData['customer_name'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                                        <input type="text" name="customer_mobile" id="customer_mobile" class="form-control" value="<?php echo h($pawnData['customer_mobile'] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" class="form-control" rows="2"><?php echo h($pawnData['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">ID Proof Type</label>
                                        <input type="text" name="id_proof_type" class="form-control" value="<?php echo h($pawnData['id_proof_type'] ?? ''); ?>" placeholder="Aadhar, PAN, etc.">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">ID Proof Number</label>
                                        <input type="text" name="id_proof_number" class="form-control" value="<?php echo h($pawnData['id_proof_number'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Pawn Items</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="itemsTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 25%;">Item Name</th>
                                            <th style="width: 15%;">Category</th>
                                            <th style="width: 10%;">Purity</th>
                                            <th style="width: 10%;">Gross Wt (g)</th>
                                            <th style="width: 10%;">Less Wt (g)</th>
                                            <th style="width: 10%;">Net Wt (g)</th>
                                            <th style="width: 10%;">Stone Wt (g)</th>
                                            <th style="width: 10%;">Stone Amt</th>
                                            <th style="width: 5%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsBody">
                                        <?php if (!empty($pawnItems)): ?>
                                            <?php foreach ($pawnItems as $index => $item): ?>
                                                <tr class="item-row">
                                                    <td>
                                                        <input type="hidden" name="item_id[]" value="<?php echo $item['id']; ?>">
                                                        <input type="text" name="item_name[]" class="form-control" value="<?php echo h($item['item_name']); ?>" required>
                                                    </td>
                                                    <td><input type="text" name="item_category[]" class="form-control" value="<?php echo h($item['item_category'] ?? ''); ?>"></td>
                                                    <td><input type="text" name="item_purity[]" class="form-control" value="<?php echo h($item['purity'] ?? ''); ?>"></td>
                                                    <td><input type="number" step="0.001" name="item_gross_weight[]" class="form-control gross-weight" value="<?php echo $item['gross_weight'] ?? 0; ?>"></td>
                                                    <td><input type="number" step="0.001" name="item_less_weight[]" class="form-control less-weight" value="<?php echo $item['less_weight'] ?? 0; ?>"></td>
                                                    <td><input type="number" step="0.001" name="item_net_weight[]" class="form-control net-weight" readonly style="background:#e9ecef;" value="<?php echo $item['net_weight'] ?? 0; ?>"></td>
                                                    <td><input type="number" step="0.001" name="item_stone_weight[]" class="form-control stone-weight" value="<?php echo $item['stone_weight'] ?? 0; ?>"></td>
                                                    <td><input type="number" step="0.01" name="item_stone_amount[]" class="form-control stone-amount" value="<?php echo $item['stone_amount'] ?? 0; ?>"></td>
                                                    <td><button type="button" class="btn btn-danger btn-sm remove-row">×</button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr class="item-row">
                                                <td><input type="text" name="item_name[]" class="form-control" required></td>
                                                <td><input type="text" name="item_category[]" class="form-control"></td>
                                                <td><input type="text" name="item_purity[]" class="form-control"></td>
                                                <td><input type="number" step="0.001" name="item_gross_weight[]" class="form-control gross-weight" value="0"></td>
                                                <td><input type="number" step="0.001" name="item_less_weight[]" class="form-control less-weight" value="0"></td>
                                                <td><input type="number" step="0.001" name="item_net_weight[]" class="form-control net-weight" readonly style="background:#e9ecef;" value="0"></td>
                                                <td><input type="number" step="0.001" name="item_stone_weight[]" class="form-control stone-weight" value="0"></td>
                                                <td><input type="number" step="0.01" name="item_stone_amount[]" class="form-control stone-amount" value="0"></td>
                                                <td><button type="button" class="btn btn-danger btn-sm remove-row">×</button></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="9">
                                                <button type="button" class="btn btn-secondary btn-sm" id="addRowBtn">Add Another Item</button>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Total Gross Weight (g)</label>
                                        <input type="text" id="total_gross_weight" class="form-control" readonly style="background:#e9ecef;" value="<?php echo number_format($pawnData['total_gross_weight'] ?? 0, 3); ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Total Less Weight (g)</label>
                                        <input type="text" id="total_less_weight" class="form-control" readonly style="background:#e9ecef;" value="<?php echo number_format($pawnData['total_less_weight'] ?? 0, 3); ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Total Net Weight (g)</label>
                                        <input type="text" id="total_net_weight" class="form-control" readonly style="background:#e9ecef;" value="<?php echo number_format($pawnData['total_net_weight'] ?? 0, 3); ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Loan Amount (₹)</label>
                                        <input type="number" step="0.01" name="loan_amount" id="loan_amount" class="form-control" readonly style="background:#e9ecef;" value="<?php echo $pawnData['loan_amount'] ?? 0; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" class="form-control" rows="3"><?php echo h($pawnData['remarks'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="text-end">
                                <button type="submit" name="update_pawn" class="btn btn-primary">Update Pawn Entry</button>
                                <a href="pawn-view.php?id=<?php echo $pawnId; ?>" class="btn btn-info">View Details</a>
                                <a href="pawn-list.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
$(document).ready(function() {
    // Calculate maturity date based on tenure
    function calculateMaturityDate() {
        var entryDate = $('input[name="entry_date"]').val();
        var tenureMonths = parseInt($('#tenure_months').val()) || 0;
        
        if (entryDate && tenureMonths > 0) {
            var date = new Date(entryDate);
            date.setMonth(date.getMonth() + tenureMonths);
            var year = date.getFullYear();
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            $('#maturity_date').val(year + '-' + month + '-' + day);
        }
    }
    
    $('#tenure_months').on('change keyup', calculateMaturityDate);
    $('input[name="entry_date"]').on('change', calculateMaturityDate);
    
    // Calculate net weight and totals
    function calculateNetWeight(row) {
        var gross = parseFloat($(row).find('.gross-weight').val()) || 0;
        var less = parseFloat($(row).find('.less-weight').val()) || 0;
        var net = gross - less;
        $(row).find('.net-weight').val(net.toFixed(3));
        calculateTotals();
    }
    
    function calculateTotals() {
        var totalGross = 0;
        var totalLess = 0;
        var totalNet = 0;
        var totalEstAmount = 0;
        var interestRate = parseFloat($('#interest_rate').val()) || 0;
        
        $('.item-row').each(function() {
            var gross = parseFloat($(this).find('.gross-weight').val()) || 0;
            var less = parseFloat($(this).find('.less-weight').val()) || 0;
            var net = gross - less;
            var stoneAmount = parseFloat($(this).find('.stone-amount').val()) || 0;
            
            totalGross += gross;
            totalLess += less;
            totalNet += net;
            totalEstAmount += (net * interestRate) + stoneAmount;
        });
        
        $('#total_gross_weight').val(totalGross.toFixed(3));
        $('#total_less_weight').val(totalLess.toFixed(3));
        $('#total_net_weight').val(totalNet.toFixed(3));
        
        var ticketCharge = parseFloat($('#ticket_charge').val()) || 0;
        var otherCharge = parseFloat($('#other_charge').val()) || 0;
        var loanAmount = totalEstAmount + ticketCharge + otherCharge;
        
        $('#loan_amount').val(loanAmount.toFixed(2));
        $('input[name="loan_amount"]').val(loanAmount.toFixed(2));
    }
    
    // Recalculate when interest rate changes
    $('#interest_rate').on('change keyup', function() {
        calculateTotals();
    });
    
    $('#ticket_charge, #other_charge').on('change keyup', function() {
        calculateTotals();
    });
    
    // Item row calculations
    $(document).on('change keyup', '.gross-weight, .less-weight, .stone-amount', function() {
        calculateNetWeight($(this).closest('.item-row'));
    });
    
    // Add new row
    $('#addRowBtn').click(function() {
        var newRow = $('.item-row:first').clone();
        newRow.find('input[type="text"], input[type="number"]').val('');
        newRow.find('input[name="item_id[]"]').remove();
        newRow.find('.gross-weight').val('0');
        newRow.find('.less-weight').val('0');
        newRow.find('.stone-weight').val('0');
        newRow.find('.stone-amount').val('0');
        newRow.find('.net-weight').val('0');
        $('#itemsBody').append(newRow);
        calculateTotals();
    });
    
    // Remove row
    $(document).on('click', '.remove-row', function() {
        if ($('.item-row').length > 1) {
            $(this).closest('.item-row').remove();
            calculateTotals();
        } else {
            alert('At least one item is required.');
        }
    });
    
    // Customer selection
    $('#customer_id').change(function() {
        var selected = $(this).find('option:selected');
        var name = selected.data('name');
        var mobile = selected.data('mobile');
        
        if (name) {
            $('#customer_name').val(name);
        }
        if (mobile) {
            $('#customer_mobile').val(mobile);
        }
    });
    
    // Initial calculation
    calculateTotals();
});
</script>

</body>
</html>