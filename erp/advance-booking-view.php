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
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$id = max(0, (int) ($_GET['id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0 || $id <= 0)
    die('Invalid advance booking.');
if (!abTableExists($conn, 'advance_bookings'))
    die('Advance booking module is not initialized. Open advance-bookings.php first.');
if (empty($_SESSION['advance_booking_csrf']))
    $_SESSION['advance_booking_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['advance_booking_csrf'];
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, (string) $_POST['csrf_token'])) {
        $message = 'Invalid request token.';
    } else {
        $stmt = $conn->prepare("UPDATE advance_bookings SET status='Cancelled',updated_by=? WHERE id=? AND business_id=? AND branch_id=? AND status IN('Active','Partially Used') AND COALESCE(used_grams,0)<=0.0000005 AND COALESCE(used_amount,0)<=0.005");
        $stmt->bind_param('iiii', $userId, $id, $businessId, $branchId);
        $stmt->execute();
        if ($stmt->affected_rows > 0)
            $message = 'Booking cancelled successfully.';
        else
            $message = 'This booking cannot be cancelled because it has already been used or is not active.';
        $stmt->close();
    }
}
$stmt = $conn->prepare('SELECT ab.*,c.customer_name,c.customer_code,c.mobile,c.email,m.metal_name,pm.method_name,p.product_code FROM advance_bookings ab LEFT JOIN customers c ON c.id=ab.customer_id AND c.business_id=ab.business_id LEFT JOIN metals m ON m.id=ab.metal_id AND m.business_id=ab.business_id LEFT JOIN payment_methods pm ON pm.id=ab.payment_method_id AND pm.business_id=ab.business_id LEFT JOIN products p ON p.id=ab.product_id AND p.business_id=ab.business_id WHERE ab.id=? AND ab.business_id=? AND ab.branch_id=? LIMIT 1');
$stmt->bind_param('iii', $id, $businessId, $branchId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$booking)
    die('Advance booking was not found.');
$usage = [];
if (abTableExists($conn, 'advance_booking_usage')) {
    $stmt = $conn->prepare('SELECT * FROM advance_booking_usage WHERE advance_booking_id=? AND business_id=? AND branch_id=? ORDER BY usage_date DESC,id DESC');
    $stmt->bind_param('iii', $id, $businessId, $branchId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $usage[] = $x;
    $stmt->close();
}
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
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
$statusClass = 'st-' . strtolower(str_replace(' ', '-', (string) ($booking['status'] ?? '')));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - <?= e($booking['booking_no']) ?></title><?php include('includes/links.php'); ?>
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
            --radius: <?= (int) $theme['border_radius_px'] ?>px
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .detail-card,
        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 10px
        }

        .detail-head {
            padding: 15px 17px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px
        }

        .detail-title {
            font: 700 20px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0
        }

        .detail-item {
            padding: 13px 15px;
            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line)
        }

        .detail-item:nth-child(4n) {
            border-right: 0
        }

        .detail-label {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 800
        }

        .detail-value {
            font-size: 12px;
            font-weight: 700;
            margin-top: 4px
        }

        .big-number {
            font-size: 21px;
            color: var(--primary-dark)
        }

        .status-badge {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800
        }

        .st-active {
            background: #eaf8f0;
            color: #168449
        }

        .st-partially-used {
            background: #fff3cd;
            color: #8a5a00
        }

        .st-completed {
            background: #e7f1ff;
            color: #0b5ed7
        }

        .st-cancelled,
        .st-refunded {
            background: #f8d7da;
            color: #b02a37
        }

        .note-box {
            padding: 14px 16px
        }

        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--primary-dark);
            letter-spacing: .06em;
            margin-bottom: 8px
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: 0;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 8px;
            padding: 8px 12px
        }

        .table {
            font-size: 10px;
            margin: 0
        }

        .table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 6%, transparent)
        }

        .table td,
        .table th {
            padding: 9px;
            border-color: var(--line)
        }

        .msg {
            padding: 10px 12px;
            border-radius: 8px;
            background: #eaf8f0;
            color: #168449;
            font-size: 11px;
            margin-bottom: 10px
        }

        @media(max-width:900px) {
            .detail-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .detail-item:nth-child(4n) {
                border-right: 1px solid var(--line)
            }

            .detail-item:nth-child(2n) {
                border-right: 0
            }
        }

        @media(max-width:560px) {
            .detail-grid {
                grid-template-columns: 1fr
            }

            .detail-item {
                border-right: 0 !important
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap"><?php if (($_GET['msg'] ?? '') === 'created'): ?>
                <div class="msg">Advance booking created successfully.</div><?php elseif (($_GET['msg'] ?? '') === 'updated'): ?>
                <div class="msg">Advance booking updated successfully.</div><?php elseif ($message !== ''): ?>
                <div class="msg"><?= e($message) ?></div><?php endif ?>
            <div class="detail-card">
                <div class="detail-head">
                    <div>
                        <div class="detail-title"><?= e($booking['booking_no']) ?></div>
                        <div class="small text-muted">Advance gold booking details</div>
                    </div>
                    <div class="d-flex gap-2"><a href="advance-bookings.php" class="btn btn-light btn-sm"><i
                                class="fa-solid fa-arrow-left me-1"></i>Back</a><?php if (in_array($booking['status'], ['Active', 'Partially Used'], true)): ?><a
                                href="advance-booking-form.php?id=<?= $id ?>" class="btn btn-theme"><i
                                    class="fa-solid fa-pen me-1"></i>Edit</a><?php endif ?></div>
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value"><span
                                class="status-badge <?= $statusClass ?>"><?= e($booking['status']) ?></span></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Booking Date</div>
                        <div class="detail-value"><?= date('d-m-Y', strtotime($booking['booking_date'])) ?>
                            <?= !empty($booking['booking_time']) ? date('h:i A', strtotime($booking['booking_time'])) : '' ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Customer</div>
                        <div class="detail-value"><?= e($booking['customer_name'] ?: '—') ?>
                            <div class="small text-muted"><?= e($booking['customer_code'] ?: '') ?>
                                <?= e($booking['mobile'] ?: '') ?></div>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Expected Purchase</div>
                        <div class="detail-value">
                            <?= !empty($booking['expected_purchase_date']) ? date('d-m-Y', strtotime($booking['expected_purchase_date'])) : '—' ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Product</div>
                        <div class="detail-value"><?= e($booking['product_name']) ?>
                            <div class="small text-muted">
                                <?= !empty($booking['product_id']) ? 'Existing Product ' . e($booking['product_code'] ?: '') : 'Manual Requirement' ?>
                            </div>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Metal / Purity</div>
                        <div class="detail-value"><?= e($booking['metal_name'] ?: '—') ?> / <?= e($booking['purity'] ?: '—') ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Locked Rate / Gram</div>
                        <div class="detail-value big-number">
                            ₹<?= number_format((float) $booking['booking_rate_per_gram'], 2) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Booked Grams</div>
                        <div class="detail-value big-number"><?= number_format((float) $booking['booked_grams'], 6) ?> g
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Advance Amount</div>
                        <div class="detail-value">₹<?= number_format((float) $booking['advance_amount'], 2) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Used Amount</div>
                        <div class="detail-value">₹<?= number_format((float) $booking['used_amount'], 2) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Balance Amount</div>
                        <div class="detail-value">₹<?= number_format((float) $booking['balance_amount'], 2) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Balance Grams</div>
                        <div class="detail-value"><?= number_format((float) $booking['balance_grams'], 6) ?> g</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value"><?= e($booking['method_name'] ?: '—') ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Payment Reference</div>
                        <div class="detail-value"><?= e($booking['payment_reference'] ?: '—') ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Created At</div>
                        <div class="detail-value"><?= date('d-m-Y h:i A', strtotime($booking['created_at'])) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Last Updated</div>
                        <div class="detail-value"><?= date('d-m-Y h:i A', strtotime($booking['updated_at'])) ?></div>
                    </div>
                </div>
                <?php if (trim((string) $booking['notes']) !== ''): ?>
                    <div class="note-box">
                        <div class="section-title">Notes</div>
                        <div><?= nl2br(e($booking['notes'])) ?></div>
                    </div><?php endif ?>
            </div>
            <div class="table-card">
                <div class="detail-head">
                    <div>
                        <div class="section-title mb-0">Booking Usage History</div>
                        <div class="small text-muted">Invoices that consumed this booking amount / grams.</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Used Grams</th>
                                <th>Booked Rate</th>
                                <th>Used Amount</th>
                            </tr>
                        </thead>
                        <tbody><?php if ($usage):
                            foreach ($usage as $i => $u): ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td><?= date('d-m-Y h:i A', strtotime($u['usage_date'])) ?></td>
                                        <td><?= e($u['invoice_no'] ?: ('Sale #' . (int) $u['sale_id'])) ?></td>
                                        <td><?= number_format((float) $u['used_grams'], 6) ?> g</td>
                                        <td>₹<?= number_format((float) $u['used_rate_per_gram'], 2) ?></td>
                                        <td>₹<?= number_format((float) $u['used_amount'], 2) ?></td>
                                    </tr><?php endforeach; else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">This booking has not been used in
                                        billing yet.</td>
                                </tr><?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if (in_array($booking['status'], ['Active', 'Partially Used'], true) && (float) $booking['used_grams'] <= 0.0000005 && (float) $booking['used_amount'] <= 0.005): ?>
                <div class="detail-card">
                    <div class="note-box">
                        <div class="section-title">Cancel Booking</div>
                        <div class="small text-muted mb-2">Cancellation is allowed only before any booking amount or grams
                            are used.</div>
                        <form method="post" onsubmit="return confirm('Cancel this advance booking?');"><input type="hidden"
                                name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action"
                                value="cancel"><button class="btn btn-outline-danger btn-sm"><i
                                    class="fa-solid fa-ban me-1"></i>Cancel Booking</button></form>
                    </div>
                </div><?php endif ?>
            <?php include('includes/footer.php'); ?>
        </div>
    </main><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
</body>

</html>