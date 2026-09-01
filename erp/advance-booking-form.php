<?php
if (session_status() === PHP_SESSION_NONE)
  session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/includes/config.php', __DIR__ . '/super-admin/includes/config.php'] as $f) {
  if (is_file($f)) {
    require_once $f;
    break;
  }
}
if (!isset($conn) || !($conn instanceof mysqli))
  die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
function e($v): string
{
  return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function abTableExists(mysqli $c, string $t): bool
{
  $t = $c->real_escape_string($t);
  $r = $c->query("SHOW TABLES LIKE '{$t}'");
  return $r && $r->num_rows > 0;
}
function abColumnExists(mysqli $c, string $t, string $col): bool
{
  $t = $c->real_escape_string($t);
  $col = $c->real_escape_string($col);
  $r = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
  return $r && $r->num_rows > 0;
}
function abEnsureTables(mysqli $c): void
{
  if (!abTableExists($c, 'advance_bookings')) {
    $sql = "CREATE TABLE advance_bookings (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,business_id INT NOT NULL,branch_id INT NOT NULL,booking_no VARCHAR(100) NOT NULL,booking_date DATE NOT NULL,booking_time TIME NULL,customer_id INT NOT NULL,metal_id INT NULL,purity VARCHAR(50) NULL,product_id INT NULL,product_name VARCHAR(255) NOT NULL,booking_rate_per_gram DECIMAL(14,2) NOT NULL DEFAULT 0.00,advance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,booked_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,expected_purchase_date DATE NULL,payment_method_id INT NULL,payment_reference VARCHAR(255) NULL,status ENUM('Active','Partially Used','Completed','Cancelled','Refunded') NOT NULL DEFAULT 'Active',used_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,used_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,balance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,balance_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,notes TEXT NULL,created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by INT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY uq_advance_booking_no(business_id,branch_id,booking_no),KEY idx_advance_customer(business_id,customer_id),KEY idx_advance_status(business_id,branch_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$c->query($sql))
      die('Unable to create advance_bookings table: ' . $c->error);
  }
  if (!abTableExists($c, 'advance_booking_usage')) {
    $sql = "CREATE TABLE advance_booking_usage (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,business_id INT NOT NULL,branch_id INT NOT NULL,advance_booking_id BIGINT UNSIGNED NOT NULL,sale_id INT NULL,invoice_no VARCHAR(100) NULL,used_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,used_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,used_rate_per_gram DECIMAL(14,2) NOT NULL DEFAULT 0.00,usage_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_booking_usage(advance_booking_id),KEY idx_booking_sale(sale_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$c->query($sql))
      die('Unable to create advance_booking_usage table: ' . $c->error);
  }
}
function abFinancialYearShort(string $date): string
{
  $ts = strtotime($date);
  $y = (int) date('Y', $ts);
  $m = (int) date('n', $ts);
  $s = $m >= 4 ? $y : $y - 1;
  return substr((string) $s, -2) . '-' . substr((string) ($s + 1), -2);
}
function abNextBookingNo(mysqli $c, int $bid, int $branch, string $date): string
{
  $fy = abFinancialYearShort($date);
  $prefix = 'AB/' . $fy . '/';
  $like = $prefix . '%';
  $stmt = $c->prepare('SELECT booking_no FROM advance_bookings WHERE business_id=? AND branch_id=? AND booking_no LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
  $stmt->bind_param('iis', $bid, $branch, $like);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $n = 1;
  if ($row && preg_match('/(\d+)$/', (string) $row['booking_no'], $m))
    $n = (int) $m[1] + 1;
  return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}
function abCurrentRate(mysqli $c, int $bid, int $branch, int $metalId, string $purity): float
{
  $hasPurity = abColumnExists($c, 'metal_rates', 'purity');
  if ($hasPurity && trim($purity) !== '') {
    $sql = "SELECT rate_per_gram FROM metal_rates WHERE business_id=? AND metal_id=? AND is_current=1 AND (branch_id=? OR branch_id IS NULL) AND TRIM(CAST(purity AS CHAR))=? ORDER BY (branch_id=?) DESC,effective_from DESC,id DESC LIMIT 1";
    $stmt = $c->prepare($sql);
    $stmt->bind_param('iiisi', $bid, $metalId, $branch, $purity, $branch);
  } else {
    $sql = "SELECT rate_per_gram FROM metal_rates WHERE business_id=? AND metal_id=? AND is_current=1 AND (branch_id=? OR branch_id IS NULL) ORDER BY (branch_id=?) DESC,effective_from DESC,id DESC LIMIT 1";
    $stmt = $c->prepare($sql);
    $stmt->bind_param('iiii', $bid, $metalId, $branch, $branch);
  }
  if (!$stmt)
    return 0.0;
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float) ($r['rate_per_gram'] ?? 0);
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($businessId <= 0 || $branchId <= 0)
  die('A valid business and branch must be selected.');
abEnsureTables($conn);
if (empty($_SESSION['advance_booking_csrf']))
  $_SESSION['advance_booking_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['advance_booking_csrf'];
$bookingId = max(0, (int) ($_GET['id'] ?? $_POST['booking_id'] ?? 0));
$edit = null;
if ($bookingId > 0) {
  $stmt = $conn->prepare('SELECT * FROM advance_bookings WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
  $stmt->bind_param('iii', $bookingId, $businessId, $branchId);
  $stmt->execute();
  $edit = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$edit)
    die('Advance booking was not found.');
  if (!in_array((string) $edit['status'], ['Active', 'Partially Used'], true))
    die('Only active or partially used bookings can be edited.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf_token']) || !hash_equals($csrf, (string) $_POST['csrf_token'])) {
    $error = 'Invalid or expired request token. Refresh the page.';
  } else {
    $bookingDate = trim((string) ($_POST['booking_date'] ?? date('Y-m-d')));
    $bookingTime = trim((string) ($_POST['booking_time'] ?? date('H:i')));
    $customerId = max(0, (int) ($_POST['customer_id'] ?? 0));
    $metalId = max(0, (int) ($_POST['metal_id'] ?? 0));
    $purity = trim((string) ($_POST['purity'] ?? ''));
    $productMode = (string) ($_POST['product_mode'] ?? 'existing');
    $productId = $productMode === 'existing' ? max(0, (int) ($_POST['product_id'] ?? 0)) : 0;
    $manualName = trim((string) ($_POST['manual_product_name'] ?? ''));
    $advance = round(max(0, (float) ($_POST['advance_amount'] ?? 0)), 2);
    $paymentMethodId = max(0, (int) ($_POST['payment_method_id'] ?? 0));
    $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
    $expectedDate = trim((string) ($_POST['expected_purchase_date'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate))
      $error = 'Valid booking date is required.';
    elseif ($customerId <= 0)
      $error = 'Please select a customer.';
    elseif ($metalId <= 0)
      $error = 'Please select metal.';
    elseif ($advance <= 0)
      $error = 'Advance amount must be greater than zero.';
    elseif ($paymentMethodId <= 0)
      $error = 'Please select payment method.';
    $customerRow = null;
    if ($error === '') {
      $stmt = $conn->prepare('SELECT id FROM customers WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
      $stmt->bind_param('ii', $customerId, $businessId);
      $stmt->execute();
      $customerRow = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if (!$customerRow)
        $error = 'Selected customer is invalid.';
    }
    $productName = '';
    if ($error === '') {
      if ($productMode === 'existing') {
        $stmt = $conn->prepare('SELECT product_name,metal_id,purity FROM products WHERE id=? AND business_id=? LIMIT 1');
        $stmt->bind_param('ii', $productId, $businessId);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$p) {
          $error = 'Selected product is invalid.';
        } else {
          $productName = (string) $p['product_name'];
          if ($metalId <= 0)
            $metalId = (int) $p['metal_id'];
          if ($purity === '')
            $purity = trim((string) $p['purity']);
        }
      } else {
        if ($manualName === '')
          $error = 'Enter the required product name.';
        else {
          $productId = 0;
          $productName = $manualName;
        }
      }
    }
    if ($error === '') {
      $rate = $edit ? (float) $edit['booking_rate_per_gram'] : abCurrentRate($conn, $businessId, $branchId, $metalId, $purity);
      if ($rate <= 0)
        $error = 'Current rate per gram is not available for the selected metal / purity.';
      else {
        $grams = round($advance / $rate, 6);
        if ($edit) {
          $usedAmount = (float) $edit['used_amount'];
          $usedGrams = (float) $edit['used_grams'];
          $balanceAmount = max(0, $advance - $usedAmount);
          $balanceGrams = max(0, $grams - $usedGrams);
          $status = ($usedGrams > 0.0000005 || $usedAmount > 0.005) ? 'Partially Used' : 'Active';
          $stmt = $conn->prepare('UPDATE advance_bookings SET booking_date=?,booking_time=?,customer_id=?,metal_id=?,purity=?,product_id=?,product_name=?,advance_amount=?,booked_grams=?,expected_purchase_date=?,payment_method_id=?,payment_reference=?,balance_amount=?,balance_grams=?,status=?,notes=?,updated_by=? WHERE id=? AND business_id=? AND branch_id=?');
          $productIdDb = $productId > 0 ? $productId : null;
          $expectedDb = $expectedDate !== '' ? $expectedDate : null;
          $stmt->bind_param('ssiisisddsisddssiiii', $bookingDate, $bookingTime, $customerId, $metalId, $purity, $productIdDb, $productName, $advance, $grams, $expectedDb, $paymentMethodId, $paymentReference, $balanceAmount, $balanceGrams, $status, $notes, $userId, $bookingId, $businessId, $branchId);
          if (!$stmt->execute())
            $error = 'Unable to update advance booking: ' . $stmt->error;
          $stmt->close();
          if ($error === '') {
            header('Location: advance-booking-view.php?id=' . $bookingId . '&msg=updated');
            exit;
          }
        } else {
          $conn->begin_transaction();
          try {
            $bookingNo = abNextBookingNo($conn, $businessId, $branchId, $bookingDate);
            $status = 'Active';
            $balanceAmount = $advance;
            $balanceGrams = $grams;
            $productIdDb = $productId > 0 ? $productId : null;
            $expectedDb = $expectedDate !== '' ? $expectedDate : null;
            $stmt = $conn->prepare('INSERT INTO advance_bookings(business_id,branch_id,booking_no,booking_date,booking_time,customer_id,metal_id,purity,product_id,product_name,booking_rate_per_gram,advance_amount,booked_grams,expected_purchase_date,payment_method_id,payment_reference,status,used_amount,used_grams,balance_amount,balance_grams,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,?,?,?,?)');
            if (!$stmt)
              throw new RuntimeException($conn->error);
            $stmt->bind_param('iisssiisisdddsissddsi', $businessId, $branchId, $bookingNo, $bookingDate, $bookingTime, $customerId, $metalId, $purity, $productIdDb, $productName, $rate, $advance, $grams, $expectedDb, $paymentMethodId, $paymentReference, $status, $balanceAmount, $balanceGrams, $notes, $userId);
            if (!$stmt->execute())
              throw new RuntimeException($stmt->error);
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            $conn->commit();
            header('Location: advance-booking-view.php?id=' . $newId . '&msg=created');
            exit;
          } catch (Throwable $ex) {
            $conn->rollback();
            $error = 'Unable to save advance booking: ' . $ex->getMessage();
          }
        }
      }
    }
  }
}

$customers = [];
$stmt = $conn->prepare('SELECT id,customer_code,customer_name,mobile FROM customers WHERE business_id=? AND is_active=1 ORDER BY customer_name');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
  $customers[] = $x;
$stmt->close();
$products = [];
$stmt = $conn->prepare('SELECT id,product_code,product_name,metal_id,purity FROM products WHERE business_id=? AND is_active=1 ORDER BY product_name');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
  $products[] = $x;
$stmt->close();
$metals = [];
$stmt = $conn->prepare('SELECT id,metal_name FROM metals WHERE business_id=?' . (abColumnExists($conn, 'metals', 'is_active') ? ' AND is_active=1' : '') . ' ORDER BY metal_name');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
  $metals[] = $x;
$stmt->close();
$paymentMethods = [];
$stmt = $conn->prepare('SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
  $paymentMethods[] = $x;
$stmt->close();
$purities = [];
$hasPurity = abColumnExists($conn, 'metal_rates', 'purity');
if ($hasPurity) {
  $stmt = $conn->prepare("SELECT DISTINCT metal_id,TRIM(CAST(purity AS CHAR)) purity FROM metal_rates WHERE business_id=? AND is_current=1 AND purity IS NOT NULL AND TRIM(CAST(purity AS CHAR))<>'' ORDER BY metal_id,purity");
  $stmt->bind_param('i', $businessId);
  $stmt->execute();
  $r = $stmt->get_result();
  while ($x = $r->fetch_assoc()) {
    $mid = (int) $x['metal_id'];
    if (!isset($purities[$mid]))
      $purities[$mid] = [];
    $purities[$mid][] = (string) $x['purity'];
  }
  $stmt->close();
}
$rates = [];
$rateSql = 'SELECT mr.metal_id,' . ($hasPurity ? "TRIM(CAST(mr.purity AS CHAR))" : "''") . ' purity,mr.rate_per_gram,mr.branch_id,mr.effective_from,mr.id FROM metal_rates mr WHERE mr.business_id=? AND mr.is_current=1 AND (mr.branch_id=? OR mr.branch_id IS NULL) ORDER BY mr.metal_id,' . ($hasPurity ? 'mr.purity,' : '') . '(mr.branch_id=?) DESC,mr.effective_from DESC,mr.id DESC';
$stmt = $conn->prepare($rateSql);
$stmt->bind_param('iii', $businessId, $branchId, $branchId);
$stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc()) {
  $key = (int) $x['metal_id'] . '|' . trim((string) $x['purity']);
  if (!isset($rates[$key]))
    $rates[$key] = (float) $x['rate_per_gram'];
}
$stmt->close();
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12, 'sidebar_width_px' => 230, 'sidebar_gradient_1' => '#171c21', 'sidebar_gradient_2' => '#20272d', 'sidebar_gradient_3' => '#101419'];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
  $stmt->bind_param('i', $businessId);
  $stmt->execute();
  $x = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();
  foreach ($theme as $k => $v)
    if (isset($x[$k]) && $x[$k] !== '')
      $theme[$k] = $x[$k];
}
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$isEdit = (bool) $edit;
$defaults = $edit ?: ['booking_date' => date('Y-m-d'), 'booking_time' => date('H:i:s'), 'customer_id' => '', 'metal_id' => '', 'purity' => '', 'product_id' => '', 'product_name' => '', 'booking_rate_per_gram' => 0, 'advance_amount' => '', 'booked_grams' => 0, 'expected_purchase_date' => '', 'payment_method_id' => '', 'payment_reference' => '', 'notes' => ''];
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($businessName) ?> - <?= $isEdit ? 'Edit' : 'New' ?> Advance Booking</title>
  <?php include('includes/links.php'); ?>
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
  <style>
    :root {
      --primary: <?= e($theme['primary_color']) ?>;
      --primary-dark: <?= e($theme['primary_dark_color']) ?>;
      --primary-soft: <?= e($theme['primary_soft_color']) ?>;
      --page-bg: <?= e($theme['page_background']) ?>;
      --card-bg: <?= e($theme['card_background']) ?>;
      --text: <?= e($theme['text_color']) ?>;
      --muted: <?= e($theme['muted_text_color']) ?>;
      --line: <?= e($theme['border_color']) ?>;
      --radius: <?= (int) $theme['border_radius_px'] ?>px;
      --sidebar-width: <?= (int) $theme['sidebar_width_px'] ?>px
    }

    body {
      background: var(--page-bg);
      color: var(--text);
      font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
    }

    .form-card {
      background: var(--card-bg);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      overflow: hidden
    }

    .form-head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--line);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px
    }

    .form-title {
      font: 700 20px
        <?= json_encode($theme['heading_font_family']) ?>
        , serif
    }

    .section {
      padding: 18px;
      border-bottom: 1px solid var(--line)
    }

    .section:last-child {
      border-bottom: 0
    }

    .section-title {
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: 14px;
      color: var(--primary-dark)
    }

    .field-label {
      font-size: 10px;
      font-weight: 700;
      margin-bottom: 5px
    }

    .form-control,
    .form-select {
      font-size: 11px;
      min-height: 38px;
      border-color: var(--line);
      border-radius: 9px;
      background: var(--card-bg);
      color: var(--text)
    }

    .btn-theme {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      border: 0;
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      border-radius: 9px;
      padding: 9px 15px
    }

    .rate-box {
      background: var(--primary-soft);
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 12px;
      height: 100%
    }

    .rate-value {
      font-size: 22px;
      font-weight: 800;
      color: var(--primary-dark)
    }

    .gram-value {
      font-size: 20px;
      font-weight: 800
    }

    .mode-box {
      display: flex;
      gap: 18px;
      padding: 8px 0
    }

    .alert-error {
      background: #f8d7da;
      border: 1px solid #f1aeb5;
      color: #842029;
      border-radius: 9px;
      padding: 10px 12px;
      font-size: 11px;
      margin-bottom: 10px
    }

    .select2-container {
      width: 100% !important
    }

    .select2-container .select2-selection--single {
      height: 38px;
      border-color: var(--line);
      border-radius: 9px
    }

    .select2-container .select2-selection--single .select2-selection__rendered {
      line-height: 36px;
      font-size: 11px
    }

    .select2-container .select2-selection--single .select2-selection__arrow {
      height: 36px
    }
  </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
  <main class="app-main"><?php include('includes/nav.php'); ?>
    <div class="content-wrap"><?php if ($error !== ''): ?>
        <div class="alert-error"><i class="fa-solid fa-circle-exclamation me-2"></i><?= e($error) ?></div><?php endif ?>
      <form class="form-card" method="post" id="bookingForm">
        <div class="form-head">
          <div>
            <div class="form-title"><?= $isEdit ? 'Edit Advance Booking' : 'New Advance Booking' ?></div>
            <div class="small text-muted">
              <?= $isEdit ? 'Booking rate remains locked while editing.' : 'Lock today\'s metal rate and calculate customer booking grams.' ?>
            </div>
          </div><a href="<?= $isEdit ? 'advance-booking-view.php?id=' . (int) $bookingId : 'advance-bookings.php' ?>"
            class="btn btn-light btn-sm"><i class="fa-solid fa-arrow-left me-2"></i>Back</a>
        </div><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="booking_id"
          value="<?= (int) $bookingId ?>">
        <div class="section">
          <div class="section-title">Booking Information</div>
          <div class="row g-3">
            <div class="col-md-3"><label class="field-label">Booking Date *</label><input type="date"
                class="form-control" name="booking_date" id="bookingDate"
                value="<?= e(substr((string) $defaults['booking_date'], 0, 10)) ?>" required></div>
            <div class="col-md-3"><label class="field-label">Booking Time *</label><input type="time"
                class="form-control" name="booking_time" value="<?= e(substr((string) $defaults['booking_time'], 0, 5)) ?>"
                required></div>
            <div class="col-md-6"><label class="field-label">Customer *</label><select class="form-select select2"
                name="customer_id" required>
                <option value="">Select customer</option><?php foreach ($customers as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) $defaults['customer_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['customer_name'] . (!empty($c['customer_code']) ? ' - ' . $c['customer_code'] : '') . (!empty($c['mobile']) ? ' - ' . $c['mobile'] : '')) ?>
                  </option><?php endforeach ?>
              </select></div>
          </div>
        </div>
        <div class="section">
          <div class="section-title">Product Requirement</div>
          <div class="mode-box"><label><input type="radio" name="product_mode" value="existing" id="modeExisting"
                <?= !$isEdit || !empty($defaults['product_id']) ? 'checked' : '' ?>> Existing Product</label><label><input
                type="radio" name="product_mode" value="manual" id="modeManual"
                <?= $isEdit && empty($defaults['product_id']) ? 'checked' : '' ?>> Manual Product Name</label></div>
          <div class="row g-3">
            <div class="col-md-6" id="existingProductWrap"><label class="field-label">Product</label><select
                class="form-select select2" name="product_id" id="productId">
                <option value="">Select existing product</option><?php foreach ($products as $p): ?>
                  <option value="<?= (int) $p['id'] ?>" data-metal="<?= (int) $p['metal_id'] ?>"
                    data-purity="<?= e($p['purity']) ?>" <?= (int) $defaults['product_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                    <?= e($p['product_name'] . ' - ' . $p['product_code']) ?></option><?php endforeach ?>
              </select></div>
            <div class="col-md-6 d-none" id="manualProductWrap"><label class="field-label">Manual Product Name
                *</label><input class="form-control" name="manual_product_name" id="manualProductName"
                value="<?= $isEdit && empty($defaults['product_id']) ? e($defaults['product_name']) : '' ?>"
                placeholder="Example: Bridal Gold Chain"></div>
            <div class="col-md-3"><label class="field-label">Metal *</label><select class="form-select" name="metal_id"
                id="metalId" required>
                <option value="">Select metal</option><?php foreach ($metals as $m): ?>
                  <option value="<?= (int) $m['id'] ?>" <?= (int) $defaults['metal_id'] === (int) $m['id'] ? 'selected' : '' ?>>
                    <?= e($m['metal_name']) ?></option><?php endforeach ?>
              </select></div>
            <div class="col-md-3"><label class="field-label">Purity</label><select class="form-select" name="purity"
                id="purity">
                <option value="">Select purity</option>
              </select></div>
          </div>
        </div>
        <div class="section">
          <div class="section-title">Rate & Advance</div>
          <div class="row g-3 align-items-stretch">
            <div class="col-md-4">
              <div class="rate-box">
                <div class="field-label">Current / Locked Rate Per Gram</div>
                <div class="rate-value">₹<span
                    id="rateText"><?= number_format((float) $defaults['booking_rate_per_gram'], 2) ?></span></div>
                <div class="small text-muted mt-1">
                  <?= $isEdit ? 'This original booking rate is frozen.' : 'Loaded from current metal rate for this branch.' ?>
                </div><input type="hidden" id="lockedRate" value="<?= e($defaults['booking_rate_per_gram']) ?>">
              </div>
            </div>
            <div class="col-md-4"><label class="field-label">Advance Amount *</label><input type="number" min="0.01"
                step="0.01" class="form-control" name="advance_amount" id="advanceAmount"
                value="<?= e($defaults['advance_amount']) ?>" required>
              <div class="small text-muted mt-1">Amount received now from customer.</div>
            </div>
            <div class="col-md-4">
              <div class="rate-box">
                <div class="field-label">Booking Grams</div>
                <div class="gram-value"><span
                    id="gramsText"><?= number_format((float) $defaults['booked_grams'], 6, '.', '') ?></span> g</div>
                <div class="small text-muted mt-1">Advance Amount ÷ Locked Rate</div>
              </div>
            </div>
          </div>
        </div>
        <div class="section">
          <div class="section-title">Payment & Purchase Plan</div>
          <div class="row g-3">
            <div class="col-md-4"><label class="field-label">Payment Method *</label><select class="form-select"
                name="payment_method_id" required>
                <option value="">Select payment method</option><?php foreach ($paymentMethods as $pm): ?>
                  <option value="<?= (int) $pm['id'] ?>"
                    <?= (int) $defaults['payment_method_id'] === (int) $pm['id'] ? 'selected' : '' ?>><?= e($pm['method_name']) ?>
                  </option><?php endforeach ?>
              </select></div>
            <div class="col-md-4"><label class="field-label">Payment Reference</label><input class="form-control"
                name="payment_reference" value="<?= e($defaults['payment_reference']) ?>"
                placeholder="UPI / Txn / Receipt reference"></div>
            <div class="col-md-4"><label class="field-label">Expected Purchase Date</label><input type="date"
                class="form-control" name="expected_purchase_date" value="<?= e($defaults['expected_purchase_date']) ?>">
            </div>
            <div class="col-12"><label class="field-label">Notes</label><textarea class="form-control" rows="3"
                name="notes" placeholder="Booking notes"><?= e($defaults['notes']) ?></textarea></div>
          </div>
        </div>
        <div class="section text-end"><a href="advance-bookings.php" class="btn btn-light btn-sm me-2">Cancel</a><button
            class="btn btn-theme"><i
              class="fa-solid fa-floppy-disk me-2"></i><?= $isEdit ? 'Update Booking' : 'Save Booking' ?></button></div>
      </form><?php include('includes/footer.php'); ?>
    </div>
  </main><?php include('includes/script.php'); ?>
  <script src="assets/js/script.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script>
    (function () {
      'use strict'; var rates = <?= json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; var purities = <?= json_encode($purities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; var editMode = <?= $isEdit ? 'true' : 'false' ?>; var initialPurity = <?= json_encode((string) $defaults['purity']) ?>; var metal = document.getElementById('metalId'), purity = document.getElementById('purity'), product = document.getElementById('productId'), advance = document.getElementById('advanceAmount'), rateText = document.getElementById('rateText'), gramsText = document.getElementById('gramsText'), lockedRate = document.getElementById('lockedRate'), existingWrap = document.getElementById('existingProductWrap'), manualWrap = document.getElementById('manualProductWrap'), manualInput = document.getElementById('manualProductName');
      function money(v) { return Number(v || 0).toFixed(2) } function grams(v) { return Number(v || 0).toFixed(6) }
      function fillPurities(selected) { var mid = String(metal.value || ''); var list = purities[mid] || purities[Number(mid)] || []; purity.innerHTML = '<option value="">Select purity</option>' + list.map(function (p) { return '<option value="' + String(p).replace(/"/g, '&quot;') + '">' + p + '</option>'; }).join(''); if (selected && list.indexOf(String(selected)) !== -1) purity.value = String(selected); }
      function currentRate() { if (editMode) return Number(lockedRate.value || 0); var key = String(metal.value || 0) + '|' + String(purity.value || ''); var v = Number(rates[key] || 0); if (!(v > 0)) { v = Number(rates[String(metal.value || 0) + '|'] || 0); } lockedRate.value = String(v); return v; }
      function recalc() { var rate = currentRate(), amt = Number(advance.value || 0); rateText.textContent = money(rate); gramsText.textContent = grams(rate > 0 ? amt / rate : 0); }
      function applyProduct() { var opt = product.options[product.selectedIndex]; if (!opt || !opt.value) return; var m = opt.getAttribute('data-metal') || ''; var p = opt.getAttribute('data-purity') || ''; if (m) { metal.value = m; fillPurities(p); if (p) purity.value = p; } recalc(); }
      function setMode() { var manual = document.getElementById('modeManual').checked; existingWrap.classList.toggle('d-none', manual); manualWrap.classList.toggle('d-none', !manual); product.required = !manual; manualInput.required = manual; if (manual) product.value = ''; }
      document.querySelectorAll('input[name="product_mode"]').forEach(function (el) { el.addEventListener('change', setMode); }); product.addEventListener('change', applyProduct); metal.addEventListener('change', function () { fillPurities(''); recalc(); }); purity.addEventListener('change', recalc); advance.addEventListener('input', recalc); setMode(); fillPurities(initialPurity); if (initialPurity) purity.value = initialPurity; recalc(); if (window.jQuery && window.jQuery.fn.select2) window.jQuery('.select2').select2({ width: '100%' });
    })();
  </script>
</body>

</html>