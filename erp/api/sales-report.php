<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function out($success, $message, array $extra = array(), $status = 200)
{
    http_response_code((int)$status);
    echo json_encode(array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

foreach (array(
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php'
) as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    out(false, 'Database configuration is not available.', array(), 500);
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    out(false, 'Your session has expired. Please log in again.', array(), 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0) {
    out(false, 'A valid business must be selected.', array(), 403);
}

function tableExistsReport(mysqli $conn, $table)
{
    $safe = $conn->real_escape_string((string)$table);
    $r = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows > 0;
}

function columnExistsReport(mysqli $conn, $table, $column)
{
    $safeTable = $conn->real_escape_string((string)$table);
    $safeColumn = $conn->real_escape_string((string)$column);
    $r = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $r && $r->num_rows > 0;
}

function bindDynamicReport(mysqli_stmt $stmt, $types, array &$params)
{
    if ($types === '') {
        return;
    }
    $bind = array($types);
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind);
}

function validDateReport($value)
{
    if ($value === '') {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function defaultFromDateReport()
{
    return date('Y-m-01');
}

function defaultToDateReport()
{
    return date('Y-m-d');
}

if (!tableExistsReport($conn, 'sales')) {
    out(false, 'Required sales table was not found.', array(), 500);
}

$action = trim((string)($_GET['action'] ?? 'list'));

if ($action === 'bootstrap') {
    $customers = array();
    $stmt = $conn->prepare("SELECT DISTINCT c.id,c.customer_code,c.customer_name
        FROM customers c
        INNER JOIN sales s ON s.customer_id=c.id AND s.business_id=c.business_id
        WHERE c.business_id=?
          AND COALESCE(s.workflow_status,'Posted') <> 'Cancelled'" . ($branchId > 0 ? " AND s.branch_id=?" : "") . "
        ORDER BY c.customer_name,c.id");
    if ($stmt) {
        if ($branchId > 0) {
            $stmt->bind_param('ii', $businessId, $branchId);
        } else {
            $stmt->bind_param('i', $businessId);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $customers[] = $row;
        }
        $stmt->close();
    }

    $billTypes = array();
    $sql = "SELECT DISTINCT bill_type FROM sales WHERE business_id=? AND COALESCE(workflow_status,'Posted') <> 'Cancelled'";
    if ($branchId > 0) {
        $sql .= " AND branch_id=?";
    }
    $sql .= " ORDER BY bill_type";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($branchId > 0) {
            $stmt->bind_param('ii', $businessId, $branchId);
        } else {
            $stmt->bind_param('i', $businessId);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            if ((string)$row['bill_type'] !== '') {
                $billTypes[] = (string)$row['bill_type'];
            }
        }
        $stmt->close();
    }

    $paymentStatuses = array();
    $sql = "SELECT DISTINCT payment_status FROM sales WHERE business_id=? AND COALESCE(workflow_status,'Posted') <> 'Cancelled'";
    if ($branchId > 0) {
        $sql .= " AND branch_id=?";
    }
    $sql .= " ORDER BY FIELD(payment_status,'Unpaid','Partial','Paid'),payment_status";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($branchId > 0) {
            $stmt->bind_param('ii', $businessId, $branchId);
        } else {
            $stmt->bind_param('i', $businessId);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            if ((string)$row['payment_status'] !== '') {
                $paymentStatuses[] = (string)$row['payment_status'];
            }
        }
        $stmt->close();
    }

    out(true, 'Sales report filters loaded.', array(
        'customers' => $customers,
        'bill_types' => $billTypes,
        'payment_statuses' => $paymentStatuses,
        'defaults' => array(
            'from_date' => defaultFromDateReport(),
            'to_date' => defaultToDateReport()
        )
    ));
}

if ($action !== 'list') {
    out(false, 'Invalid action.', array(), 400);
}

$fromDate = trim((string)($_GET['from_date'] ?? defaultFromDateReport()));
$toDate = trim((string)($_GET['to_date'] ?? defaultToDateReport()));
$customerId = (int)($_GET['customer_id'] ?? 0);
$billType = trim((string)($_GET['bill_type'] ?? ''));
$paymentStatus = trim((string)($_GET['payment_status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

if (!validDateReport($fromDate)) {
    $fromDate = defaultFromDateReport();
}
if (!validDateReport($toDate)) {
    $toDate = defaultToDateReport();
}
if ($fromDate > $toDate) {
    $tmp = $fromDate;
    $fromDate = $toDate;
    $toDate = $tmp;
}

/*
 * IMPORTANT: Cancelled invoices are excluded at SQL level.
 * This same WHERE clause is used for both rows and summary, so cancelled
 * invoice amounts can never enter report totals.
 */
$where = " WHERE s.business_id=? AND COALESCE(s.workflow_status,'Posted') <> 'Cancelled'";
$types = 'i';
$params = array($businessId);

if ($branchId > 0) {
    $where .= ' AND s.branch_id=?';
    $types .= 'i';
    $params[] = $branchId;
}

$where .= ' AND s.invoice_date>=? AND s.invoice_date<=?';
$types .= 'ss';
$params[] = $fromDate;
$params[] = $toDate;

if ($customerId > 0) {
    $where .= ' AND s.customer_id=?';
    $types .= 'i';
    $params[] = $customerId;
}

if ($billType !== '') {
    $where .= ' AND s.bill_type=?';
    $types .= 's';
    $params[] = $billType;
}

if (in_array($paymentStatus, array('Unpaid', 'Partial', 'Paid'), true)) {
    $where .= ' AND s.payment_status=?';
    $types .= 's';
    $params[] = $paymentStatus;
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (s.invoice_no LIKE ? OR COALESCE(s.customer_name,'') LIKE ? OR COALESCE(s.customer_mobile,'') LIKE ?)";
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$methodSelect = "'' AS method_name";
if (tableExistsReport($conn, 'sale_payments') && tableExistsReport($conn, 'payment_methods')) {
    $methodSelect = "COALESCE((
        SELECT GROUP_CONCAT(DISTINCT pm.method_name ORDER BY sp.id SEPARATOR ', ')
        FROM sale_payments sp
        INNER JOIN payment_methods pm ON pm.id=sp.payment_method_id AND pm.business_id=sp.business_id
        WHERE sp.sale_id=s.id AND sp.business_id=s.business_id
    ),'') AS method_name";
}

/*
 * Advance Booking Collected / Applied amount for each sale.
 * The source of truth is advance_booking_usage.used_amount because one sale can
 * use one or more advance bookings. Fall back to sales.advance_booking_amount
 * only for older installations that stored the applied value directly in sales.
 */
$advanceBookingSelect = "0 AS advance_booking_collected";
if (tableExistsReport($conn, 'advance_booking_usage')) {
    $advanceBookingSelect = "COALESCE((
        SELECT SUM(abu.used_amount)
        FROM advance_booking_usage abu
        WHERE abu.sale_id=s.id
          AND abu.business_id=s.business_id
          AND abu.branch_id=s.branch_id
    ),0) AS advance_booking_collected";
} elseif (columnExistsReport($conn, 'sales', 'advance_booking_amount')) {
    $advanceBookingSelect = "COALESCE(s.advance_booking_amount,0) AS advance_booking_collected";
}

$listSql = "SELECT
        s.id,
        s.invoice_no AS bill_no,
        s.invoice_date AS bill_date,
        s.invoice_time AS bill_time,
        s.customer_id,
        s.customer_name,
        s.customer_mobile,
        s.bill_type,
        s.subtotal,
        s.discount_amount,
        s.taxable_amount,
        s.cgst_amount,
        s.sgst_amount,
        s.igst_amount,
        s.round_off,
        s.grand_total,
        s.paid_amount,
        s.balance_amount,
        s.payment_status,
        s.workflow_status,
        s.cancelled_at,
        {$advanceBookingSelect},
        {$methodSelect}
    FROM sales s
    {$where}
    ORDER BY s.invoice_date DESC,s.invoice_time DESC,s.id DESC";

$stmt = $conn->prepare($listSql);
if (!$stmt) {
    out(false, 'Unable to prepare sales report: ' . $conn->error, array(), 500);
}
$listParams = $params;
bindDynamicReport($stmt, $types, $listParams);
$stmt->execute();
$r = $stmt->get_result();
$rows = array();
while ($row = $r->fetch_assoc()) {
    $row['bill_date_display'] = !empty($row['bill_date']) ? date('d-m-Y', strtotime($row['bill_date'])) : '';
    $row['bill_time_display'] = !empty($row['bill_time']) ? date('h:i A', strtotime($row['bill_time'])) : '';
    $rows[] = $row;
}
$stmt->close();

$summarySql = "SELECT
        COUNT(*) AS total_bills,
        COALESCE(SUM(s.subtotal),0) AS subtotal,
        COALESCE(SUM(s.discount_amount),0) AS discount_amount,
        COALESCE(SUM(s.taxable_amount),0) AS taxable_amount,
        COALESCE(SUM(s.cgst_amount),0) AS cgst_amount,
        COALESCE(SUM(s.sgst_amount),0) AS sgst_amount,
        COALESCE(SUM(s.igst_amount),0) AS igst_amount,
        COALESCE(SUM(s.grand_total),0) AS grand_total,
        COALESCE(SUM(s.paid_amount),0) AS paid_amount,
        COALESCE(SUM(s.balance_amount),0) AS balance_amount
    FROM sales s
    {$where}";

$stmt = $conn->prepare($summarySql);
if (!$stmt) {
    out(false, 'Unable to prepare sales report summary: ' . $conn->error, array(), 500);
}
$summaryParams = $params;
bindDynamicReport($stmt, $types, $summaryParams);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$summary) {
    $summary = array();
}

$advanceBookingCollectedTotal = 0.0;
foreach ($rows as $reportRow) {
    $advanceBookingCollectedTotal += (float)($reportRow['advance_booking_collected'] ?? 0);
}
$summary['advance_booking_collected'] = round($advanceBookingCollectedTotal, 2);
$summary['advance_booking_amount'] = round($advanceBookingCollectedTotal, 2);

out(true, 'Sales report loaded.', array(
    'rows' => $rows,
    'summary' => $summary,
    'period' => array(
        'from' => $fromDate,
        'to' => $toDate,
        'from_display' => date('d-m-Y', strtotime($fromDate)),
        'to_display' => date('d-m-Y', strtotime($toDate))
    )
));