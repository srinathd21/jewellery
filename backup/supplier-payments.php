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

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('moneyf')) {
    function moneyf($amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }
}

if (!function_exists('addAuditLog')) {
    function addAuditLog(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, ?int $referenceId, string $description): void
    {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if ($stmt) {
            $stmt->bind_param(
                'iississs',
                $businessId,
                $userId,
                $module,
                $action,
                $referenceId,
                $description,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

function generateSupplierPaymentNo(mysqli $conn, int $businessId, bool $hasBusinessId): string
{
    $prefix = 'SP' . date('Ymd');
    $like = $prefix . '%';
    $lastNo = '';

    if ($hasBusinessId) {
        $stmt = $conn->prepare("
            SELECT payment_no
            FROM supplier_payments
            WHERE business_id = ?
              AND payment_no LIKE ?
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('is', $businessId, $like);
        }
    } else {
        $stmt = $conn->prepare("
            SELECT payment_no
            FROM supplier_payments
            WHERE payment_no LIKE ?
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $like);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $lastNo = (string)($row['payment_no'] ?? '');
        $stmt->close();
    }

    $running = 1;

    if ($lastNo !== '' && preg_match('/(\d{4})$/', $lastNo, $m)) {
        $running = ((int)$m[1]) + 1;
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

$pageTitle = 'Supplier Payments';
$page_title = 'Supplier Payments';
$currentPage = 'supplier-payments';

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

if (!$stmt) {
    die('Role check failed.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));

if (!in_array($roleName, ['admin', 'manager', 'stock'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'suppliers')) {
    die('Required table `suppliers` not found. Please import the SQL first.');
}

if (!tableExists($conn, 'purchases')) {
    die('Required table `purchases` not found. Please import the SQL first.');
}

if (!tableExists($conn, 'supplier_payments')) {
    die('Required table `supplier_payments` not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$supHasBusinessId       = hasColumn($conn, 'suppliers', 'business_id');
$supHasSupplierCode     = hasColumn($conn, 'suppliers', 'supplier_code');
$supHasSupplierName     = hasColumn($conn, 'suppliers', 'supplier_name');
$supHasMobile           = hasColumn($conn, 'suppliers', 'mobile');
$supHasOpeningBalance   = hasColumn($conn, 'suppliers', 'opening_balance');
$supHasBalanceType      = hasColumn($conn, 'suppliers', 'balance_type');
$supHasIsActive         = hasColumn($conn, 'suppliers', 'is_active');

$purHasBusinessId       = hasColumn($conn, 'purchases', 'business_id');
$purHasSupplierId       = hasColumn($conn, 'purchases', 'supplier_id');
$purHasPurchaseNo       = hasColumn($conn, 'purchases', 'purchase_no');
$purHasPurchaseDate     = hasColumn($conn, 'purchases', 'purchase_date');
$purHasGrandTotal       = hasColumn($conn, 'purchases', 'grand_total');
$purHasPaidAmount       = hasColumn($conn, 'purchases', 'paid_amount');
$purHasBalanceAmount    = hasColumn($conn, 'purchases', 'balance_amount');
$purHasPaymentStatus    = hasColumn($conn, 'purchases', 'payment_status');
$purHasUpdatedAt        = hasColumn($conn, 'purchases', 'updated_at');

$payHasBusinessId       = hasColumn($conn, 'supplier_payments', 'business_id');
$payHasPaymentNo        = hasColumn($conn, 'supplier_payments', 'payment_no');
$payHasPaymentDate      = hasColumn($conn, 'supplier_payments', 'payment_date');
$payHasSupplierId       = hasColumn($conn, 'supplier_payments', 'supplier_id');
$payHasPurchaseId       = hasColumn($conn, 'supplier_payments', 'purchase_id');
$payHasPaymentMethodId  = hasColumn($conn, 'supplier_payments', 'payment_method_id');
$payHasReferenceNo      = hasColumn($conn, 'supplier_payments', 'reference_no');
$payHasAmount           = hasColumn($conn, 'supplier_payments', 'amount');
$payHasNotes            = hasColumn($conn, 'supplier_payments', 'notes');
$payHasCreatedBy        = hasColumn($conn, 'supplier_payments', 'created_by');
$payHasCreatedAt        = hasColumn($conn, 'supplier_payments', 'created_at');

$paymentMethodExists    = tableExists($conn, 'payment_methods');

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

if (!empty($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (!empty($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

/* -------------------------------------------------------
   LOAD SUPPLIERS
------------------------------------------------------- */
$suppliers = [];

$sql = "SELECT id";

if ($supHasSupplierName) {
    $sql .= ", supplier_name";
} else {
    $sql .= ", '' AS supplier_name";
}

if ($supHasSupplierCode) {
    $sql .= ", supplier_code";
} else {
    $sql .= ", '' AS supplier_code";
}

if ($supHasMobile) {
    $sql .= ", mobile";
} else {
    $sql .= ", '' AS mobile";
}

if ($supHasOpeningBalance) {
    $sql .= ", opening_balance";
} else {
    $sql .= ", 0 AS opening_balance";
}

if ($supHasBalanceType) {
    $sql .= ", balance_type";
} else {
    $sql .= ", 'Cr' AS balance_type";
}

$sql .= " FROM suppliers WHERE 1=1";

if ($supHasBusinessId) {
    $sql .= " AND business_id = ?";
}

if ($supHasIsActive) {
    $sql .= " AND is_active = 1";
}

$sql .= " ORDER BY supplier_name ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($supHasBusinessId) {
        $stmt->bind_param('i', $businessId);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $suppliers[] = $row;
    }

    $stmt->close();
}

/* -------------------------------------------------------
   LOAD PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];

if ($paymentMethodExists) {
    $sql = "SELECT id, method_name FROM payment_methods WHERE 1=1";

    if (hasColumn($conn, 'payment_methods', 'is_active')) {
        $sql .= " AND is_active = 1";
    }

    $sql .= " ORDER BY id ASC";

    $res = $conn->query($sql);

    while ($res && ($row = $res->fetch_assoc())) {
        $paymentMethods[] = $row;
    }
}

/* -------------------------------------------------------
   DEFAULTS
------------------------------------------------------- */
$selectedSupplierId = (int)($_GET['supplier_id'] ?? 0);
$paymentNo = generateSupplierPaymentNo($conn, $businessId, $payHasBusinessId);
$paymentDate = date('Y-m-d');
$purchaseId = 0;
$paymentMethodId = 0;
$referenceNo = '';
$amount = '';
$notes = '';

/* -------------------------------------------------------
   SAVE PAYMENT - POST REDIRECT GET
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $paymentNo = trim((string)($_POST['payment_no'] ?? ''));
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $selectedSupplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($paymentNo === '') {
        $paymentNo = generateSupplierPaymentNo($conn, $businessId, $payHasBusinessId);
    }

    $paymentAmount = is_numeric($amount) ? (float)$amount : 0.00;

    $errors = [];

    if ($paymentDate === '') {
        $errors[] = 'Payment date is required.';
    }

    if ($selectedSupplierId <= 0) {
        $errors[] = 'Please select supplier.';
    }

    if ($paymentAmount <= 0) {
        $errors[] = 'Payment amount must be greater than zero.';
    }

    /* Validate supplier */
    if (empty($errors)) {
        $sql = "SELECT id FROM suppliers WHERE id = ?";

        if ($supHasBusinessId) {
            $sql .= " AND business_id = ?";
        }

        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $errors[] = 'Supplier validation failed.';
        } else {
            if ($supHasBusinessId) {
                $stmt->bind_param('ii', $selectedSupplierId, $businessId);
            } else {
                $stmt->bind_param('i', $selectedSupplierId);
            }

            $stmt->execute();
            $res = $stmt->get_result();

            if (!$res || $res->num_rows <= 0) {
                $errors[] = 'Invalid supplier selected.';
            }

            $stmt->close();
        }
    }

    /* Validate selected purchase and balance */
    $purchaseRow = null;

    if (empty($errors) && $purchaseId > 0) {
        $sql = "
            SELECT id, supplier_id,
                   " . ($purHasPurchaseNo ? "purchase_no," : "'' AS purchase_no,") . "
                   " . ($purHasGrandTotal ? "grand_total," : "0 AS grand_total,") . "
                   " . ($purHasPaidAmount ? "paid_amount," : "0 AS paid_amount,") . "
                   " . ($purHasBalanceAmount ? "balance_amount" : "0 AS balance_amount") . "
            FROM purchases
            WHERE id = ?
              AND supplier_id = ?
        ";

        if ($purHasBusinessId) {
            $sql .= " AND business_id = ?";
        }

        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $errors[] = 'Purchase validation failed.';
        } else {
            if ($purHasBusinessId) {
                $stmt->bind_param('iii', $purchaseId, $selectedSupplierId, $businessId);
            } else {
                $stmt->bind_param('ii', $purchaseId, $selectedSupplierId);
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $purchaseRow = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$purchaseRow) {
                $errors[] = 'Selected purchase not found for this supplier.';
            } else {
                $purchaseBalance = (float)($purchaseRow['balance_amount'] ?? 0);

                if ($purchaseBalance > 0 && $paymentAmount > $purchaseBalance) {
                    $errors[] = 'Payment amount cannot be greater than selected purchase balance.';
                }
            }
        }
    }

    if (!empty($errors)) {
        $_SESSION['flash_error'] = implode('<br>', array_map('h', $errors));
        header('Location: supplier-payments.php?supplier_id=' . $selectedSupplierId);
        exit;
    }

    $purchaseIdDb = $purchaseId > 0 ? $purchaseId : null;
    $paymentMethodIdDb = $paymentMethodId > 0 ? $paymentMethodId : null;

    $conn->begin_transaction();

    try {
        /* Insert supplier payment */
        $fields = [];
        $placeholders = [];
        $types = '';
        $values = [];

        if ($payHasBusinessId) {
            $fields[] = 'business_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $businessId;
        }

        if ($payHasPaymentNo) {
            $fields[] = 'payment_no';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $paymentNo;
        }

        if ($payHasPaymentDate) {
            $fields[] = 'payment_date';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $paymentDate;
        }

        if ($payHasSupplierId) {
            $fields[] = 'supplier_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $selectedSupplierId;
        }

        if ($payHasPurchaseId) {
            $fields[] = 'purchase_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $purchaseIdDb;
        }

        if ($payHasPaymentMethodId) {
            $fields[] = 'payment_method_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $paymentMethodIdDb;
        }

        if ($payHasReferenceNo) {
            $fields[] = 'reference_no';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $referenceNo;
        }

        if ($payHasAmount) {
            $fields[] = 'amount';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = $paymentAmount;
        }

        if ($payHasNotes) {
            $fields[] = 'notes';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $notes;
        }

        if ($payHasCreatedBy) {
            $fields[] = 'created_by';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $userId;
        }

        if ($payHasCreatedAt) {
            $fields[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        if (empty($fields)) {
            throw new Exception('No supplier payment columns found.');
        }

        $sql = "INSERT INTO supplier_payments (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Failed to prepare supplier payment insert: ' . $conn->error);
        }

        if ($types !== '') {
            $bindValues = [];
            $bindValues[] = $types;

            for ($i = 0; $i < count($values); $i++) {
                $bindValues[] = &$values[$i];
            }

            call_user_func_array([$stmt, 'bind_param'], $bindValues);
        }

        if (!$stmt->execute()) {
            $stmtError = $stmt->error;
            $stmt->close();
            throw new Exception('Failed to save supplier payment: ' . $stmtError);
        }

        $supplierPaymentId = (int)$stmt->insert_id;
        $stmt->close();

        /* Update selected purchase paid and balance */
        if ($purchaseId > 0 && $purchaseRow) {
            $oldPaid = (float)($purchaseRow['paid_amount'] ?? 0);
            $grandTotal = (float)($purchaseRow['grand_total'] ?? 0);

            $newPaid = $oldPaid + $paymentAmount;
            $newBalance = $grandTotal - $newPaid;

            if ($newBalance < 0) {
                $newBalance = 0;
            }

            $paymentStatus = 'Unpaid';

            if ($newPaid > 0 && $newPaid < $grandTotal) {
                $paymentStatus = 'Partial';
            } elseif ($newPaid >= $grandTotal && $grandTotal > 0) {
                $paymentStatus = 'Paid';
            }

            $setParts = [];
            $updateTypes = '';
            $updateValues = [];

            if ($purHasPaidAmount) {
                $setParts[] = 'paid_amount = ?';
                $updateTypes .= 'd';
                $updateValues[] = $newPaid;
            }

            if ($purHasBalanceAmount) {
                $setParts[] = 'balance_amount = ?';
                $updateTypes .= 'd';
                $updateValues[] = $newBalance;
            }

            if ($purHasPaymentStatus) {
                $setParts[] = 'payment_status = ?';
                $updateTypes .= 's';
                $updateValues[] = $paymentStatus;
            }

            if ($purHasUpdatedAt) {
                $setParts[] = 'updated_at = NOW()';
            }

            if (!empty($setParts)) {
                $sql = "UPDATE purchases SET " . implode(', ', $setParts) . " WHERE id = ?";
                $updateTypes .= 'i';
                $updateValues[] = $purchaseId;

                if ($purHasBusinessId) {
                    $sql .= " AND business_id = ?";
                    $updateTypes .= 'i';
                    $updateValues[] = $businessId;
                }

                $sql .= " LIMIT 1";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    throw new Exception('Failed to prepare purchase payment update: ' . $conn->error);
                }

                $bindValues = [];
                $bindValues[] = $updateTypes;

                for ($i = 0; $i < count($updateValues); $i++) {
                    $bindValues[] = &$updateValues[$i];
                }

                call_user_func_array([$stmt, 'bind_param'], $bindValues);

                if (!$stmt->execute()) {
                    $stmtError = $stmt->error;
                    $stmt->close();
                    throw new Exception('Failed to update purchase balance: ' . $stmtError);
                }

                $stmt->close();
            }
        }

        addAuditLog(
            $conn,
            $businessId,
            $userId,
            'Supplier Payments',
            'Create',
            $supplierPaymentId,
            'Created supplier payment ' . $paymentNo
        );

        $conn->commit();

        $_SESSION['flash_success'] = 'Supplier payment saved successfully. Payment No: ' . $paymentNo;
        header('Location: supplier-payments.php?supplier_id=' . $selectedSupplierId);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();

        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: supplier-payments.php?supplier_id=' . $selectedSupplierId);
        exit;
    }
}

/* -------------------------------------------------------
   SELECTED SUPPLIER DETAILS
------------------------------------------------------- */
$selectedSupplier = null;

if ($selectedSupplierId > 0) {
    $sql = "SELECT * FROM suppliers WHERE id = ?";

    if ($supHasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($supHasBusinessId) {
            $stmt->bind_param('ii', $selectedSupplierId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedSupplierId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $selectedSupplier = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

/* -------------------------------------------------------
   PENDING PURCHASES FOR SELECTED SUPPLIER
------------------------------------------------------- */
$pendingPurchases = [];

if ($selectedSupplierId > 0) {
    $sql = "
        SELECT
            id,
            " . ($purHasPurchaseNo ? "purchase_no," : "'' AS purchase_no,") . "
            " . ($purHasPurchaseDate ? "purchase_date," : "NULL AS purchase_date,") . "
            " . ($purHasGrandTotal ? "grand_total," : "0 AS grand_total,") . "
            " . ($purHasPaidAmount ? "paid_amount," : "0 AS paid_amount,") . "
            " . ($purHasBalanceAmount ? "balance_amount," : "0 AS balance_amount,") . "
            " . ($purHasPaymentStatus ? "payment_status" : "'Unpaid' AS payment_status") . "
        FROM purchases
        WHERE supplier_id = ?
    ";

    if ($purHasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    if ($purHasBalanceAmount) {
        $sql .= " AND balance_amount > 0";
    }

    $sql .= " ORDER BY purchase_date ASC, id ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($purHasBusinessId) {
            $stmt->bind_param('ii', $selectedSupplierId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedSupplierId);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && ($row = $res->fetch_assoc())) {
            $pendingPurchases[] = $row;
        }

        $stmt->close();
    }
}

/* -------------------------------------------------------
   SUMMARY COUNTS
------------------------------------------------------- */
$totalPayable = 0.00;
$todayPaid = 0.00;
$monthPaid = 0.00;

if ($selectedSupplierId > 0) {
    $opening = 0.00;

    if ($selectedSupplier) {
        $opening = (float)($selectedSupplier['opening_balance'] ?? 0);

        if (($selectedSupplier['balance_type'] ?? 'Cr') === 'Dr') {
            $opening = -$opening;
        }
    }

    $purchaseBalance = 0.00;

    $sql = "
        SELECT COALESCE(SUM(balance_amount), 0) AS total_balance
        FROM purchases
        WHERE supplier_id = ?
    ";

    if ($purHasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($purHasBusinessId) {
            $stmt->bind_param('ii', $selectedSupplierId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedSupplierId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $purchaseBalance = (float)($row['total_balance'] ?? 0);
        $stmt->close();
    }

    $unlinkedPayments = 0.00;

    if ($payHasPurchaseId) {
        $sql = "
            SELECT COALESCE(SUM(amount), 0) AS unlinked_paid
            FROM supplier_payments
            WHERE supplier_id = ?
              AND purchase_id IS NULL
        ";

        if ($payHasBusinessId) {
            $sql .= " AND business_id = ?";
        }

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            if ($payHasBusinessId) {
                $stmt->bind_param('ii', $selectedSupplierId, $businessId);
            } else {
                $stmt->bind_param('i', $selectedSupplierId);
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $unlinkedPayments = (float)($row['unlinked_paid'] ?? 0);
            $stmt->close();
        }
    }

    $totalPayable = $opening + $purchaseBalance - $unlinkedPayments;

    if ($totalPayable < 0) {
        $totalPayable = 0;
    }
}

/* Today paid */
$sql = "
    SELECT COALESCE(SUM(amount), 0) AS total_paid
    FROM supplier_payments
    WHERE payment_date = CURDATE()
";

$params = [];
$types = '';

if ($payHasBusinessId) {
    $sql .= " AND business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $todayPaid = (float)($row['total_paid'] ?? 0);
    $stmt->close();
}

/* Month paid */
$sql = "
    SELECT COALESCE(SUM(amount), 0) AS total_paid
    FROM supplier_payments
    WHERE DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
";

$params = [];
$types = '';

if ($payHasBusinessId) {
    $sql .= " AND business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $monthPaid = (float)($row['total_paid'] ?? 0);
    $stmt->close();
}

/* -------------------------------------------------------
   RECENT PAYMENTS
------------------------------------------------------- */
$recentPayments = [];

$sql = "
    SELECT
        sp.*,
        s.supplier_name,
        " . ($supHasSupplierCode ? "s.supplier_code," : "'' AS supplier_code,") . "
        " . ($purHasPurchaseNo ? "p.purchase_no," : "'' AS purchase_no,") . "
        " . ($paymentMethodExists ? "pm.method_name" : "'' AS method_name") . "
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON s.id = sp.supplier_id
    LEFT JOIN purchases p ON p.id = sp.purchase_id
    " . ($paymentMethodExists ? "LEFT JOIN payment_methods pm ON pm.id = sp.payment_method_id" : "") . "
    WHERE 1=1
";

$params = [];
$types = '';

if ($payHasBusinessId) {
    $sql .= " AND sp.business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}

if ($selectedSupplierId > 0) {
    $sql .= " AND sp.supplier_id = ?";
    $params[] = $selectedSupplierId;
    $types .= 'i';
}

$sql .= " ORDER BY sp.id DESC LIMIT 15";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $bindValues = [];
        $bindValues[] = $types;

        for ($i = 0; $i < count($params); $i++) {
            $bindValues[] = &$params[$i];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $recentPayments[] = $row;
    }

    $stmt->close();
}

include('includes/head.php');
?>

<style>
    .supplier-summary-table th {
        width: 45%;
        background: #f8f9fa;
    }

    .payment-card h3 {
        font-weight: 700;
    }
</style>

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

                <div class="row mb-3">
                    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h4 class="mb-1">Supplier Payments</h4>
                            <p class="text-muted mb-0">Record supplier payments and update purchase balances</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="purchases.php" class="btn btn-secondary">Purchases</a>
                            <a href="suppliers.php" class="btn btn-secondary">Suppliers</a>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4">
                        <div class="card text-center payment-card">
                            <div class="card-body">
                                <h3 class="text-danger mt-2">₹<?php echo money($totalPayable); ?></h3>
                                <p class="text-muted mb-0">Selected Supplier Payable</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card text-center payment-card">
                            <div class="card-body">
                                <h3 class="text-success mt-2">₹<?php echo money($todayPaid); ?></h3>
                                <p class="text-muted mb-0">Today Paid</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card text-center payment-card">
                            <div class="card-body">
                                <h3 class="text-primary mt-2">₹<?php echo money($monthPaid); ?></h3>
                                <p class="text-muted mb-0">This Month Paid</p>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="post" action="supplier-payments.php" id="supplierPaymentForm">
                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Payment Entry</h4>

                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Payment No</label>
                                            <input type="text" name="payment_no" class="form-control" value="<?php echo h($paymentNo); ?>" required>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Payment Date</label>
                                            <input type="date" name="payment_date" class="form-control" value="<?php echo h($paymentDate); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                            <select
                                                name="supplier_id"
                                                id="supplier_id"
                                                class="form-select"
                                                required
                                                onchange="if(this.value){ window.location.href='supplier-payments.php?supplier_id=' + encodeURIComponent(this.value); }"
                                            >
                                                <option value="">Select Supplier</option>
                                                <?php foreach ($suppliers as $supplier): ?>
                                                    <option value="<?php echo (int)$supplier['id']; ?>" <?php echo $selectedSupplierId === (int)$supplier['id'] ? 'selected' : ''; ?>>
                                                        <?php
                                                        echo h($supplier['supplier_name'] ?? '');
                                                        if (!empty($supplier['supplier_code'])) {
                                                            echo ' (' . h($supplier['supplier_code']) . ')';
                                                        }
                                                        if (!empty($supplier['mobile'])) {
                                                            echo ' - ' . h($supplier['mobile']);
                                                        }
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Purchase Bill</label>
                                            <select name="purchase_id" id="purchase_id" class="form-select">
                                                <option value="">General Payment / Opening Balance</option>
                                                <?php foreach ($pendingPurchases as $purchase): ?>
                                                    <option
                                                        value="<?php echo (int)$purchase['id']; ?>"
                                                        data-balance="<?php echo h($purchase['balance_amount'] ?? 0); ?>"
                                                    >
                                                        <?php
                                                        echo h($purchase['purchase_no'] ?? '');
                                                        echo ' | ' . (!empty($purchase['purchase_date']) ? date('d-m-Y', strtotime($purchase['purchase_date'])) : '-');
                                                        echo ' | Balance ₹' . money($purchase['balance_amount'] ?? 0);
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted">Select a purchase bill to directly reduce its balance.</small>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" min="0" name="amount" id="amount" class="form-control" value="<?php echo h($amount); ?>" required>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Payment Method</label>
                                            <select name="payment_method_id" class="form-select">
                                                <option value="">Select</option>
                                                <?php foreach ($paymentMethods as $pm): ?>
                                                    <option value="<?php echo (int)$pm['id']; ?>">
                                                        <?php echo h($pm['method_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Reference No</label>
                                            <input type="text" name="reference_no" class="form-control" value="<?php echo h($referenceNo); ?>" placeholder="Cheque / UPI / Bank Ref No">
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Notes</label>
                                            <input type="text" name="notes" class="form-control" value="<?php echo h($notes); ?>" placeholder="Payment notes">
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" name="save_payment" value="1" class="btn btn-primary">
                                            Save Payment
                                        </button>
                                        <a href="supplier-payments.php" class="btn btn-secondary">Reset</a>
                                    </div>

                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Supplier Summary</h4>

                                    <?php if ($selectedSupplier): ?>
                                        <table class="table table-bordered supplier-summary-table mb-0">
                                            <tr>
                                                <th>Supplier</th>
                                                <td>
                                                    <strong><?php echo h($selectedSupplier['supplier_name'] ?? ''); ?></strong>
                                                    <?php if (!empty($selectedSupplier['supplier_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($selectedSupplier['supplier_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Mobile</th>
                                                <td><?php echo h($selectedSupplier['mobile'] ?? ''); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Opening Balance</th>
                                                <td>
                                                    ₹<?php echo money($selectedSupplier['opening_balance'] ?? 0); ?>
                                                    <?php echo h($selectedSupplier['balance_type'] ?? 'Cr'); ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Pending Bills</th>
                                                <td><?php echo count($pendingPurchases); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Total Payable</th>
                                                <td class="text-danger">
                                                    <strong>₹<?php echo money($totalPayable); ?></strong>
                                                </td>
                                            </tr>
                                        </table>
                                    <?php else: ?>
                                        <div class="text-muted">Select a supplier to see payable details.</div>
                                    <?php endif; ?>

                                    <hr>

                                    <h5 class="mb-3">Notes</h5>
                                    <ul class="mb-0 ps-3">
                                        <li>Select purchase bill to reduce that bill balance.</li>
                                        <li>Without purchase bill, payment is saved as general supplier payment.</li>
                                        <li>Payment status updates automatically for selected purchase.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Pending Purchases</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Purchase No</th>
                                        <th>Date</th>
                                        <th>Grand Total</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($pendingPurchases)): ?>
                                        <?php foreach ($pendingPurchases as $index => $purchase): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><strong><?php echo h($purchase['purchase_no'] ?? ''); ?></strong></td>
                                                <td>
                                                    <?php echo !empty($purchase['purchase_date']) ? date('d-m-Y', strtotime($purchase['purchase_date'])) : '-'; ?>
                                                </td>
                                                <td>₹<?php echo money($purchase['grand_total'] ?? 0); ?></td>
                                                <td class="text-success">₹<?php echo money($purchase['paid_amount'] ?? 0); ?></td>
                                                <td class="text-danger"><strong>₹<?php echo money($purchase['balance_amount'] ?? 0); ?></strong></td>
                                                <td>
                                                    <?php
                                                    $status = (string)($purchase['payment_status'] ?? 'Unpaid');
                                                    $badge = 'danger';
                                                    if ($status === 'Paid') {
                                                        $badge = 'success';
                                                    } elseif ($status === 'Partial') {
                                                        $badge = 'warning';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo h($badge); ?>"><?php echo h($status); ?></span>
                                                </td>
                                                <td>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-primary"
                                                        onclick="selectPurchase('<?php echo (int)$purchase['id']; ?>','<?php echo h($purchase['balance_amount'] ?? 0); ?>')"
                                                    >
                                                        Pay
                                                    </button>
                                                    <a href="purchase-view.php?id=<?php echo (int)$purchase['id']; ?>" class="btn btn-sm btn-info">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">
                                                <?php echo $selectedSupplierId > 0 ? 'No pending purchases found for this supplier.' : 'Select supplier to view pending purchases.'; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Recent Supplier Payments</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Payment No</th>
                                        <th>Date</th>
                                        <th>Supplier</th>
                                        <th>Purchase</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th class="text-end">Amount</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentPayments)): ?>
                                        <?php foreach ($recentPayments as $index => $row): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><strong><?php echo h($row['payment_no'] ?? ''); ?></strong></td>
                                                <td>
                                                    <?php echo !empty($row['payment_date']) ? date('d-m-Y', strtotime($row['payment_date'])) : '-'; ?>
                                                </td>
                                                <td>
                                                    <?php echo h($row['supplier_name'] ?? ''); ?>
                                                    <?php if (!empty($row['supplier_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($row['supplier_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($row['purchase_no'] ?? 'General'); ?></td>
                                                <td><?php echo h($row['method_name'] ?? ''); ?></td>
                                                <td><?php echo h($row['reference_no'] ?? ''); ?></td>
                                                <td class="text-end"><strong>₹<?php echo money($row['amount'] ?? 0); ?></strong></td>
                                                <td><?php echo h($row['notes'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No supplier payments found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function selectPurchase(purchaseId, balanceAmount) {
    const purchaseSelect = document.getElementById('purchase_id');
    const amountInput = document.getElementById('amount');

    if (purchaseSelect) {
        purchaseSelect.value = purchaseId;
    }

    if (amountInput) {
        amountInput.value = parseFloat(balanceAmount || 0).toFixed(2);
        amountInput.focus();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const purchaseSelect = document.getElementById('purchase_id');
    const amountInput = document.getElementById('amount');

    if (purchaseSelect && amountInput) {
        purchaseSelect.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];

            if (selected && selected.value && selected.getAttribute('data-balance')) {
                amountInput.value = parseFloat(selected.getAttribute('data-balance') || 0).toFixed(2);
            }
        });
    }
});
</script>

</body>
</html>