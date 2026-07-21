<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('productPermission')) {
    function productPermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'create' => 'can_create',
            'update' => 'can_update',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $permissions = $_SESSION['permissions'] ?? [];
        foreach (['perm.products.list', 'perm.products'] as $permissionCode) {
            if (isset($permissions[$permissionCode][$field])) {
                return (int) $permissions[$permissionCode][$field] === 1;
            }
        }

        $businessId = (int) ($_SESSION['business_id'] ?? 0);
        $roleId = (int) ($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        $sql = "SELECT rp.`{$field}`
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ('perm.products.list', 'perm.products')
                ORDER BY FIELD(p.permission_code, 'perm.products.list', 'perm.products')
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $businessId, $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row[$field] ?? 0) === 1;
    }
}

if (!productPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open products.');
}

$canView = productPermission($conn, 'view') || productPermission($conn, 'open');
$canUpdate = productPermission($conn, 'update') || productPermission($conn, 'create');
$businessId = (int) ($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (empty($_SESSION['products_csrf'])) {
    $_SESSION['products_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['products_csrf'];

function jsonResponse(bool $success, string $message = '', array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function generateUniqueBarcode(mysqli $conn, int $businessId, int $productId): string
{
    if ($productId <= 0)
        throw new RuntimeException('A valid product is required.');
    $suffix = str_pad((string) ($productId % 1000), 3, '0', STR_PAD_LEFT);
    $seqStmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(barcode,4,6) AS UNSIGNED)),0) max_sequence FROM products WHERE business_id=? AND barcode REGEXP '^890[0-9]{9}$'");
    if (!$seqStmt)
        throw new RuntimeException('Unable to read current barcode sequence.');
    $seqStmt->bind_param('i', $businessId);
    $seqStmt->execute();
    $row = $seqStmt->get_result()->fetch_assoc() ?: [];
    $seqStmt->close();
    $sequence = max(1, (int) ($row['max_sequence'] ?? 0) + 1);
    $check = $conn->prepare('SELECT id FROM products WHERE business_id=? AND barcode=? LIMIT 1');
    if (!$check)
        throw new RuntimeException('Unable to validate barcode.');
    for ($i = 0; $i < 1000; $i++, $sequence++) {
        if ($sequence > 999999)
            throw new RuntimeException('Barcode sequence limit reached.');
        $barcode = '890' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT) . $suffix;
        $check->bind_param('is', $businessId, $barcode);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            $check->close();
            return $barcode;
        }
    }
    $check->close();
    throw new RuntimeException('Unable to generate a unique barcode.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        jsonResponse(false, 'Session expired. Refresh the page and try again.', [], 419);
    }
    if (!$canView) {
        jsonResponse(false, 'You do not have permission to view products.', [], 403);
    }

    $action = (string) $_POST['action'];

    if ($action === 'list') {
        $search = trim((string) ($_POST['search'] ?? ''));
        $barcodeStatus = (string) ($_POST['barcode_status'] ?? 'missing');
        if (!in_array($barcodeStatus, ['missing', 'existing', 'all'], true)) {
            $barcodeStatus = 'missing';
        }

        $where = ['p.business_id = ?'];
        $types = 'i';
        $params = [$businessId];

        if ($barcodeStatus === 'missing') {
            $where[] = "(p.barcode IS NULL OR TRIM(p.barcode) = '')";
        } elseif ($barcodeStatus === 'existing') {
            $where[] = "(p.barcode IS NOT NULL AND TRIM(p.barcode) <> '')";
        }

        if ($search !== '') {
            $where[] = '(p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ? OR pc.category_name LIKE ?)';
            $like = '%' . $search . '%';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }

        $sql = "SELECT p.id, p.product_code, p.barcode, p.product_name, p.image_path,
                       p.gross_weight, p.net_weight, p.sale_rate, p.is_active,
                       COALESCE(pc.category_name, '') AS category_name,
                       COALESCE(m.metal_name, '') AS metal_name
                FROM products p
                LEFT JOIN product_categories pc ON pc.id = p.category_id
                LEFT JOIN metals m ON m.id = p.metal_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY (p.barcode IS NULL OR TRIM(p.barcode) = '') DESC, p.product_name ASC
                LIMIT 500";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            jsonResponse(false, 'Unable to load products.', [], 500);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();

        $statsStmt = $conn->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN barcode IS NULL OR TRIM(barcode) = '' THEN 1 ELSE 0 END) AS missing,
            SUM(CASE WHEN barcode IS NOT NULL AND TRIM(barcode) <> '' THEN 1 ELSE 0 END) AS existing,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active
            FROM products WHERE business_id = ?");
        $statsStmt->bind_param('i', $businessId);
        $statsStmt->execute();
        $stats = $statsStmt->get_result()->fetch_assoc() ?: [];
        $statsStmt->close();

        jsonResponse(true, '', ['products' => $products, 'stats' => $stats]);
    }

    if ($action === 'generate') {
        if (!$canUpdate) {
            jsonResponse(false, 'You do not have permission to generate barcodes.', [], 403);
        }

        try {
            $productId = (int) ($_POST['product_id'] ?? 0);

            $productStmt = $conn->prepare(
                'SELECT id, product_name, barcode
                 FROM products
                 WHERE id=? AND business_id=?
                 LIMIT 1'
            );

            if (!$productStmt) {
                throw new RuntimeException('Unable to validate product.');
            }

            $productStmt->bind_param('ii', $productId, $businessId);
            $productStmt->execute();
            $product = $productStmt->get_result()->fetch_assoc();
            $productStmt->close();

            if (!$product) {
                throw new RuntimeException('Product not found.');
            }

            $existingBarcode = trim((string) ($product['barcode'] ?? ''));
            if ($existingBarcode !== '') {
                jsonResponse(true, 'This product already has a saved barcode.', [
                    'barcode' => $existingBarcode,
                    'saved' => true,
                    'existing' => true,
                    'product_id' => $productId,
                ]);
            }

            $barcode = generateUniqueBarcode($conn, $businessId, $productId);
            $updateStmt = $conn->prepare('UPDATE products SET barcode=? WHERE id=? AND business_id=?');
            if (!$updateStmt) {
                throw new RuntimeException('Unable to prepare barcode auto-save.');
            }
            $updateStmt->bind_param('sii', $barcode, $productId, $businessId);
            if (!$updateStmt->execute()) {
                throw new RuntimeException('Unable to save generated barcode.');
            }
            $updateStmt->close();

            jsonResponse(true, 'Barcode generated and saved successfully.', [
                'barcode' => $barcode,
                'saved' => true,
                'existing' => false,
                'product_id' => $productId,
            ]);
        } catch (Throwable $error) {
            jsonResponse(false, $error->getMessage(), [], 500);
        }
    }

    if ($action === 'save') {
        if (!$canUpdate) {
            jsonResponse(false, 'You do not have permission to update products.', [], 403);
        }

        $itemsJson = (string) ($_POST['items'] ?? '[]');
        $items = json_decode($itemsJson, true);
        if (!is_array($items) || !$items) {
            jsonResponse(false, 'Select at least one product and enter or generate its barcode.', [], 422);
        }

        $checkProduct = $conn->prepare('SELECT id, product_name FROM products WHERE id = ? AND business_id = ? LIMIT 1');
        $checkDuplicate = $conn->prepare('SELECT id, product_name FROM products WHERE business_id = ? AND barcode = ? AND id <> ? LIMIT 1');
        $update = $conn->prepare('UPDATE products SET barcode = ? WHERE id = ? AND business_id = ?');
        if (!$checkProduct || !$checkDuplicate || !$update) {
            jsonResponse(false, 'Unable to prepare barcode update.', [], 500);
        }

        $conn->begin_transaction();
        try {
            $saved = 0;
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $barcode = preg_replace('/\s+/', '', trim((string) ($item['barcode'] ?? '')));

                if ($productId <= 0 || $barcode === '') {
                    throw new RuntimeException('Every selected product must have a barcode.');
                }
                if (strlen($barcode) > 100 || !preg_match('/^[A-Za-z0-9._\/-]+$/', $barcode)) {
                    throw new RuntimeException('Barcode ' . $barcode . ' contains unsupported characters.');
                }

                $checkProduct->bind_param('ii', $productId, $businessId);
                $checkProduct->execute();
                $product = $checkProduct->get_result()->fetch_assoc();
                if (!$product) {
                    throw new RuntimeException('A selected product is not available.');
                }

                $checkDuplicate->bind_param('isi', $businessId, $barcode, $productId);
                $checkDuplicate->execute();
                $duplicate = $checkDuplicate->get_result()->fetch_assoc();
                if ($duplicate) {
                    throw new RuntimeException('Barcode ' . $barcode . ' is already used by ' . $duplicate['product_name'] . '.');
                }

                $update->bind_param('sii', $barcode, $productId, $businessId);
                $update->execute();
                $saved++;
            }

            $conn->commit();
            $checkProduct->close();
            $checkDuplicate->close();
            $update->close();
            jsonResponse(true, $saved . ' product barcode' . ($saved === 1 ? '' : 's') . ' saved successfully.', ['saved' => $saved]);
        } catch (Throwable $error) {
            $conn->rollback();
            $checkProduct->close();
            $checkDuplicate->close();
            $update->close();
            jsonResponse(false, $error->getMessage(), [], 422);
        }
    }

    jsonResponse(false, 'Invalid request.', [], 400);
}

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 230,
];

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

$pageTitle = 'Barcode Generator';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Barcode Generator</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary:
                <?php echo e($theme['primary_color']); ?>
            ;
            --primary-dark:
                <?php echo e($theme['primary_dark_color']); ?>
            ;
            --primary-soft:
                <?php echo e($theme['primary_soft_color']); ?>
            ;
            --sidebar-gradient-1:
                <?php echo e($theme['sidebar_gradient_1']); ?>
            ;
            --sidebar-gradient-2:
                <?php echo e($theme['sidebar_gradient_2']); ?>
            ;
            --sidebar-gradient-3:
                <?php echo e($theme['sidebar_gradient_3']); ?>
            ;
            --page-bg:
                <?php echo e($theme['page_background']); ?>
            ;
            --card-bg:
                <?php echo e($theme['card_background']); ?>
            ;
            --text-color:
                <?php echo e($theme['text_color']); ?>
            ;
            --muted-color:
                <?php echo e($theme['muted_text_color']); ?>
            ;
            --border-color:
                <?php echo e($theme['border_color']); ?>
            ;
            --sidebar-width:
                <?php echo (int) $theme['sidebar_width_px']; ?>px;
            --radius:
                <?php echo (int) $theme['border_radius_px']; ?>px;
        }

        body {
            background: var(--page-bg);
            color: var(--text-color);
            font-family:
                <?php echo json_encode((string) $theme['font_family']); ?>
                , sans-serif;
        }

        .sidebar {
            background: linear-gradient(180deg, var(--sidebar-gradient-1), var(--sidebar-gradient-2), var(--sidebar-gradient-3)) !important;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .stat-card,
        .barcode-toolbar,
        .barcode-card,
        .preview-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
        }

        .stat-card {
            min-height: 84px;
            padding: 13px 15px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            display: grid;
            place-items: center;
            border-radius: calc(var(--radius)*.8);
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .stat-label {
            font-size: 10px;
            color: var(--muted-color);
        }

        .stat-value {
            margin-top: 2px;
            font-size: 23px;
            line-height: 1.1;
            font-weight: 800;
        }

        .barcode-toolbar {
            padding: 12px;
            margin-bottom: 12px;
        }

        .toolbar-grid {
            display: grid;
            grid-template-columns: minmax(260px, 1.7fr) minmax(160px, .8fr) auto auto auto;
            gap: 8px;
            align-items: center;
        }

        .filter-field {
            position: relative;
        }

        .filter-field>i {
            position: absolute;
            z-index: 3;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted-color);
            font-size: 11px;
            pointer-events: none;
        }

        .filter-field.has-icon .form-control,
        .filter-field.has-icon .form-select {
            padding-left: 34px;
        }

        .form-control,
        .form-select {
            min-height: 39px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 11px;
            box-shadow: none;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--primary) 12%, transparent);
        }

        .btn-theme,
        .btn-soft,
        .btn-reset {
            min-height: 39px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 14px;
        }

        .btn-theme {
            border: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .btn-soft {
            border: 1px solid color-mix(in srgb, var(--primary) 35%, var(--border-color));
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .btn-reset {
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
        }

        .barcode-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 310px;
            gap: 12px;
            align-items: start;
        }

        .barcode-card {
            overflow: hidden;
            position: relative;
        }

        .barcode-loading {
            position: absolute;
            inset: 0;
            z-index: 20;
            display: none;
            align-items: center;
            justify-content: center;
            background: color-mix(in srgb, var(--card-bg) 82%, transparent);
            backdrop-filter: blur(2px);
        }

        .barcode-loading.show {
            display: flex;
        }

        .loading-box {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--muted-color);
            font-size: 11px;
        }

        .barcode-table {
            margin: 0;
            font-size: 10px;
        }

        .barcode-table th {
            padding: 10px 12px;
            border-color: var(--border-color);
            background: color-mix(in srgb, var(--muted-color) 6%, transparent);
            color: var(--muted-color);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .barcode-table td {
            padding: 9px 12px;
            vertical-align: middle;
            border-color: var(--border-color);
            background: var(--card-bg) !important;
            color: var(--text-color);
        }

        .product-name {
            font-size: 11px;
            font-weight: 800;
        }

        .product-sub {
            margin-top: 2px;
            color: var(--muted-color);
            font-size: 9px;
        }

        .thumb,
        .thumb-empty {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            border-radius: 9px;
            border: 1px solid var(--border-color);
        }

        .thumb {
            object-fit: cover;
        }

        .thumb-empty {
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .barcode-input-wrap {
            display: flex;
            gap: 6px;
            min-width: 245px;
        }

        .barcode-input-wrap .form-control {
            min-height: 34px;
            height: 34px;
        }

        .mini-btn {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border: 1px solid var(--border-color);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text-color);
            display: grid;
            place-items: center;
        }

        .mini-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 9px;
            font-weight: 700;
        }

        .status-missing {
            background: #fff3df;
            color: #b76b00;
        }

        .status-existing {
            background: #eaf8f0;
            color: #168449;
        }

        .empty-state {
            padding: 58px 20px;
            text-align: center;
            color: var(--muted-color);
        }

        .preview-card {
            position: sticky;
            top: 82px;
            padding: 14px;
        }

        .preview-title {
            font-size: 12px;
            font-weight: 800;
        }

        .preview-sub {
            margin-top: 3px;
            color: var(--muted-color);
            font-size: 9px;
        }

        .label-preview {
            margin-top: 12px;
            min-height: 185px;
            padding: 14px 10px;
            border: 1px dashed var(--border-color);
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: #fff;
            color: #111;
        }

        .label-product {
            max-width: 250px;
            margin-bottom: 5px;
            font-size: 11px;
            font-weight: 800;
        }

        .label-code {
            margin-top: 3px;
            font-size: 9px;
            letter-spacing: .05em;
        }

        .preview-empty {
            color: #9aa1a8;
            font-size: 10px;
        }

        .preview-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }

        .selected-summary {
            padding: 10px 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .selected-text {
            color: var(--muted-color);
            font-size: 10px;
        }

        .qty-input {
            width: 72px;
            min-height: 34px;
            height: 34px;
            text-align: center
        }

        .printer-settings {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color)
        }

        .settings-title {
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 9px
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px
        }

        .setting-group label {
            display: block;
            margin-bottom: 4px;
            color: var(--muted-color);
            font-size: 9px;
            font-weight: 700
        }

        .setting-group .form-control,
        .setting-group .form-select {
            min-height: 34px;
            height: 34px;
            padding: 6px 8px
        }

        .setting-checks {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 10px;
            margin-top: 9px
        }

        .setting-checks label {
            font-size: 9px;
            display: flex;
            align-items: center;
            gap: 6px
        }

        #printSheet {
            display: none
        }

        .print-label {
            box-sizing: border-box;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: #fff;
            color: #000;
            page-break-inside: avoid
        }

        .print-label svg {
            max-width: 100%
        }

        .print-label .pl-product {
            font-weight: 700;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            max-width: 100%
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            min-width: 260px;
            max-width: 420px;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            box-shadow: 0 14px 35px rgba(0, 0, 0, .22);
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s;
        }

        .theme-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .theme-toast-success {
            background: #168449;
        }

        .theme-toast-error {
            background: #c0392b;
        }

        body.dark-mode,
        body[data-theme="dark"],
        html.dark-mode body,
        html[data-theme="dark"] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text-color: #f3f6f8;
            --muted-color: #9aa7b3;
            --border-color: #2c3944;
        }

        @media(max-width:1100px) {
            .barcode-layout {
                grid-template-columns: 1fr
            }

            .preview-card {
                position: relative;
                top: auto
            }

            .toolbar-grid {
                grid-template-columns: 1fr 1fr
            }

            .toolbar-grid .search-field {
                grid-column: 1/-1
            }

            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:767.98px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px
            }

            .toolbar-grid {
                grid-template-columns: 1fr
            }

            .toolbar-grid .search-field {
                grid-column: auto
            }

            .stat-grid {
                grid-template-columns: 1fr 1fr
            }

            .barcode-table thead {
                display: none
            }

            .barcode-table,
            .barcode-table tbody {
                display: block
            }

            .barcode-table tbody {
                display: grid;
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 10px
            }

            .barcode-table tbody tr {
                display: grid;
                grid-template-columns: 1fr;
                padding: 12px;
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                background: var(--card-bg)
            }

            .barcode-table tbody td {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                padding: 7px 0;
                border: 0;
                border-bottom: 1px dashed var(--border-color)
            }

            .barcode-table tbody td::before {
                content: attr(data-label);
                font-size: 9px;
                color: var(--muted-color);
                font-weight: 700;
                text-transform: uppercase
            }

            .barcode-table tbody td.main-cell {
                justify-content: flex-start
            }

            .barcode-table tbody td.main-cell::before {
                display: none
            }

            .barcode-input-wrap {
                min-width: 0;
                width: 100%
            }

            .selected-summary {
                align-items: stretch;
                flex-direction: column
            }

            .selected-summary .btn-theme {
                width: 100%
            }

            .theme-toast {
                left: 12px;
                right: 12px;
                min-width: 0;
                max-width: none
            }
        }

        @media print {
            body * {
                visibility: hidden !important
            }

            #printSheet,
            #printSheet * {
                visibility: visible !important;
            }

            #printSheet {
                position: absolute;
                left: 0;
                top: 0;
            }

            .preview-actions,
            .preview-title,
            .preview-sub {
                display: none !important
            }

            .label-preview {
                border: 0 !important;
                min-height: auto;
                margin: 0
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main">
        <?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <?php if (!$canView): ?>
                <div class="barcode-card">
                    <div class="empty-state"><i class="fa-solid fa-lock mb-2"></i>
                        <div>You do not have permission to view products.</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-gem"></i></div>
                        <div>
                            <div class="stat-label">Total Products</div>
                            <div class="stat-value" id="statTotal">0</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-barcode"></i></div>
                        <div>
                            <div class="stat-label">With Barcode</div>
                            <div class="stat-value" id="statExisting">0</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div>
                            <div class="stat-label">Missing Barcode</div>
                            <div class="stat-value" id="statMissing">0</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="stat-label">Active Products</div>
                            <div class="stat-value" id="statActive">0</div>
                        </div>
                    </div>
                </div>

                <div class="barcode-toolbar">
                    <div class="toolbar-grid">
                        <div class="filter-field has-icon search-field"><i class="fa-solid fa-magnifying-glass"></i><input
                                type="search" class="form-control" id="productSearch"
                                placeholder="Search product name, code, barcode or category..."></div>
                        <div class="filter-field has-icon"><i class="fa-solid fa-filter"></i><select class="form-select"
                                id="barcodeStatus">
                                <option value="missing">Missing barcode</option>
                                <option value="existing">Existing barcode</option>
                                <option value="all">All products</option>
                            </select></div>
                        <button type="button" class="btn-soft" id="selectVisible"><i class="fa-regular fa-square-check"></i>
                            Select Visible</button>
                        <button type="button" class="btn-soft" id="generateSelected" <?php echo $canUpdate ? '' : 'disabled'; ?>><i class="fa-solid fa-wand-magic-sparkles"></i> Generate & Auto Save</button>
                        <button type="button" class="btn-reset" id="resetFilters"><i class="fa-solid fa-rotate-left"></i>
                            Reset</button>
                    </div>
                </div>

                <div class="barcode-layout">
                    <section class="barcode-card">
                        <div class="barcode-loading" id="barcodeLoading">
                            <div class="loading-box"><i class="fa-solid fa-spinner fa-spin"></i><span>Loading
                                    products...</span></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table barcode-table align-middle" id="barcodeTable">
                                <thead>
                                    <tr>
                                        <th style="width:42px"><input type="checkbox" class="form-check-input"
                                                id="checkAll"></th>
                                        <th>Product</th>
                                        <th>Category / Metal</th>
                                        <th>Current Status</th>
                                        <th>Barcode</th>
                                        <th style="width:82px">Sticker Qty</th>
                                        <th class="text-end">Preview</th>
                                    </tr>
                                </thead>
                                <tbody id="barcodeTableBody"></tbody>
                            </table>
                        </div>
                        <div class="empty-state d-none" id="emptyState"><i class="fa-regular fa-folder-open mb-2"></i>
                            <div>No matching products found.</div>
                        </div>
                        <div class="selected-summary">
                            <div class="selected-text"><strong id="selectedCount">0</strong> product(s) selected · Enter an
                                existing barcode or generate a new one.</div><button type="button" class="btn-theme"
                                id="saveBarcodes" <?php echo $canUpdate ? '' : 'disabled'; ?>><i
                                    class="fa-solid fa-floppy-disk"></i> Save Manual Barcodes</button>
                        </div>
                    </section>

                    <aside class="preview-card" id="printArea">
                        <div class="preview-title">Barcode Label Preview</div>
                        <div class="preview-sub">Select a product, type, scan, or generate a barcode to update this preview instantly.</div>
                        <div class="label-preview" id="labelPreview">
                            <div class="preview-empty"><i class="fa-solid fa-barcode fa-2x mb-2"></i>
                                <div>Select a product to preview its label.</div>
                            </div>
                        </div>
                        <div class="printer-settings">
                            <div class="settings-title"><i class="fa-solid fa-sliders me-1"></i> Barcode Printer Settings
                            </div>
                            <div class="settings-grid">
                                <div class="setting-group"><label>Label Width (mm)</label><input type="number"
                                        class="form-control" id="labelWidth" value="50" min="20" max="150" step="0.5"></div>
                                <div class="setting-group"><label>Label Height (mm)</label><input type="number"
                                        class="form-control" id="labelHeight" value="25" min="10" max="100" step="0.5">
                                </div>
                                <div class="setting-group"><label>Labels per Row</label><input type="number"
                                        class="form-control" id="labelsPerRow" value="1" min="1" max="5"></div>
                                <div class="setting-group"><label>Horizontal Gap (mm)</label><input type="number"
                                        class="form-control" id="horizontalGap" value="2" min="0" max="20" step="0.5"></div>
                                <div class="setting-group"><label>Vertical Gap (mm)</label><input type="number"
                                        class="form-control" id="verticalGap" value="2" min="0" max="20" step="0.5"></div>
                                <div class="setting-group"><label>Page Margin (mm)</label><input type="number"
                                        class="form-control" id="pageMargin" value="2" min="0" max="30" step="0.5"></div>
                                <div class="setting-group"><label>Barcode Height (px)</label><input type="number"
                                        class="form-control" id="barcodeHeight" value="44" min="20" max="120"></div>
                                <div class="setting-group"><label>Line Width</label><input type="number"
                                        class="form-control" id="barcodeLineWidth" value="1.4" min="0.6" max="3" step="0.1">
                                </div>
                                <div class="setting-group"><label>Font Size</label><input type="number" class="form-control"
                                        id="labelFontSize" value="9" min="6" max="18"></div>
                                <div class="setting-group"><label>Orientation</label><select class="form-select"
                                        id="printOrientation">
                                        <option value="portrait">Portrait</option>
                                        <option value="landscape">Landscape</option>
                                    </select></div>
                            </div>
                            <div class="setting-checks"><label><input type="checkbox" id="showBusiness" checked>
                                    Business</label><label><input type="checkbox" id="showProduct" checked>
                                    Product</label><label><input type="checkbox" id="showProductCode" checked> Product
                                    code</label><label><input type="checkbox" id="showPrice"> Price</label><label><input
                                        type="checkbox" id="showWeight"> Weight</label><label><input type="checkbox"
                                        id="showBarcodeText" checked> Barcode number</label></div><button type="button"
                                class="btn-reset w-100 mt-2" id="savePrinterSettings"><i
                                    class="fa-solid fa-floppy-disk"></i> Save Printer Settings</button>
                        </div>
                        <div class="preview-actions"><button type="button" class="btn-soft" id="copyBarcode"><i
                                    class="fa-regular fa-copy"></i> Copy</button><button type="button" class="btn-theme"
                                id="printBarcode"><i class="fa-solid fa-print"></i> Print Selected</button></div>
                    </aside>
                </div>
            <?php endif; ?>
            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <div id="printSheet"></div>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <?php if ($canView): ?>
        <script>
            (function () {
                'use strict';
                const csrfToken = <?php echo json_encode($csrfToken); ?>;
                const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>; const businessName = <?php echo json_encode($businessName); ?>; const currencySymbol = <?php echo json_encode((string) ($_SESSION['currency_symbol'] ?? '₹')); ?>;
                const body = document.getElementById('barcodeTableBody');
                const loading = document.getElementById('barcodeLoading');
                const empty = document.getElementById('emptyState');
                const search = document.getElementById('productSearch');
                const status = document.getElementById('barcodeStatus');
                const checkAll = document.getElementById('checkAll');
                const selectedCount = document.getElementById('selectedCount');
                const preview = document.getElementById('labelPreview');
                let products = [];
                let searchTimer = null;
                let previewData = null;

                function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
                function showToast(type, message) { const toast = document.createElement('div'); toast.className = 'theme-toast theme-toast-' + type; toast.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation') + ' me-2"></i><span></span>'; toast.querySelector('span').textContent = message; document.body.appendChild(toast); requestAnimationFrame(() => toast.classList.add('show')); setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 250) }, 3200); }
                function setLoading(state) { loading.classList.toggle('show', state); }
                async function request(data) { data.append('csrf_token', csrfToken); const response = await fetch(window.location.pathname, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!response.ok || !result.success) throw new Error(result.message || 'Request failed.'); return result; }
                function updateSelected() { const checked = body.querySelectorAll('.row-check:checked').length; selectedCount.textContent = checked; const all = body.querySelectorAll('.row-check').length; checkAll.checked = all > 0 && checked === all; checkAll.indeterminate = checked > 0 && checked < all; }
                function row(product) {
                    const has = String(product.barcode || '').trim() !== ''; const image = product.image_path ? '<img src="' + escapeHtml(product.image_path) + '" class="thumb" alt="">' : '<div class="thumb-empty"><i class="fa-solid fa-gem"></i></div>'; return '<tr data-id="' + Number(product.id) + '">' +
                        '<td data-label="Select"><input type="checkbox" class="form-check-input row-check"></td>' +
                        '<td class="main-cell" data-label="Product"><div class="d-flex align-items-center gap-2">' + image + '<div><div class="product-name">' + escapeHtml(product.product_name) + '</div><div class="product-sub">' + escapeHtml(product.product_code) + '</div></div></div></td>' +
                        '<td data-label="Category / Metal"><div>' + escapeHtml(product.category_name || '—') + '</div><div class="product-sub">' + escapeHtml(product.metal_name || '—') + '</div></td>' +
                        '<td data-label="Current Status"><span class="status-badge ' + (has ? 'status-existing' : 'status-missing') + '">' + (has ? 'Available' : 'Missing') + '</span></td>' +
                        '<td data-label="Barcode"><div class="barcode-input-wrap"><input type="text" class="form-control barcode-value" value="' + escapeHtml(product.barcode || '') + '" placeholder="Scan, type or generate" maxlength="100" autocomplete="off"><button type="button" class="mini-btn generate-one" title="Generate"><i class="fa-solid fa-wand-magic-sparkles"></i></button></div></td>' +
                        '<td data-label="Sticker Qty"><input type="number" class="form-control qty-input" value="1" min="1" max="500"></td>' +
                        '<td class="text-end" data-label="Preview"><button type="button" class="mini-btn preview-one ms-auto" title="Preview"><i class="fa-solid fa-eye"></i></button></td></tr>';
                }
                function render() {
                    body.innerHTML = products.map(row).join('');
                    document.getElementById('barcodeTable').classList.toggle('d-none', products.length === 0);
                    empty.classList.toggle('d-none', products.length > 0);
                    checkAll.checked = false;
                    checkAll.indeterminate = false;
                    updateSelected();
                    const firstRow = body.querySelector('tr[data-id]');
                    if (firstRow) previewRow(firstRow, false);
                }
                function updateStats(stats) { document.getElementById('statTotal').textContent = Number(stats.total || 0).toLocaleString(); document.getElementById('statExisting').textContent = Number(stats.existing || 0).toLocaleString(); document.getElementById('statMissing').textContent = Number(stats.missing || 0).toLocaleString(); document.getElementById('statActive').textContent = Number(stats.active || 0).toLocaleString(); }
                async function load() { setLoading(true); const data = new FormData(); data.append('action', 'list'); data.append('search', search.value.trim()); data.append('barcode_status', status.value); try { const result = await request(data); products = result.products || []; render(); updateStats(result.stats || {}); } catch (error) { showToast('error', error.message) } finally { setLoading(false) } }
                async function generateBarcode(productId) { const data = new FormData(); data.append('action', 'generate'); data.append('product_id', String(productId)); return await request(data); }
                async function generateForRow(rowEl) {
                    if (!canUpdate) return;
                    const input = rowEl.querySelector('.barcode-value');
                    const button = rowEl.querySelector('.generate-one');
                    const badge = rowEl.querySelector('.status-badge');
                    button.disabled = true;
                    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    try {
                        const result = await generateBarcode(Number(rowEl.dataset.id));
                        input.value = result.barcode;
                        const product = products.find(item => Number(item.id) === Number(rowEl.dataset.id));
                        if (product) product.barcode = result.barcode;
                        rowEl.querySelector('.row-check').checked = true;
                        if (badge) {
                            badge.className = 'status-badge status-existing';
                            badge.textContent = 'Saved';
                        }
                        updateSelected();
                        previewRow(rowEl, false);
                        showToast('success', result.message || 'Barcode generated and saved.');
                    } catch (error) {
                        showToast('error', error.message);
                    } finally {
                        button.disabled = false;
                        button.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i>';
                    }
                }
                function previewRow(rowEl, showError = true) {
                    const id = Number(rowEl.dataset.id);
                    const product = products.find(item => Number(item.id) === id);
                    if (!product) return;

                    const barcode = rowEl.querySelector('.barcode-value').value.trim();
                    const qty = Math.max(1, Number(rowEl.querySelector('.qty-input').value || 1));
                    previewData = { ...product, barcode, qty };
                    renderLivePreview(showError);
                }

                function renderLivePreview(showError = false) {
                    if (!previewData) {
                        preview.innerHTML = '<div class="preview-empty"><i class="fa-solid fa-barcode fa-2x mb-2"></i><div>Select a product to preview its label.</div></div>';
                        return;
                    }

                    const c = cfg();
                    const product = previewData;
                    const barcode = String(product.barcode || '').trim();
                    const labelWidth = Math.max(20, Number(c.labelWidth || 50));
                    const labelHeight = Math.max(10, Number(c.labelHeight || 25));
                    const fontSize = Math.max(6, Number(c.labelFontSize || 9));
                    const barcodeHeight = Math.max(20, Number(c.barcodeHeight || 44));
                    const lineWidth = Math.max(0.6, Number(c.barcodeLineWidth || 1.4));

                    preview.style.width = '100%';
                    preview.style.minHeight = Math.max(150, labelHeight * 4) + 'px';
                    preview.style.aspectRatio = labelWidth + ' / ' + labelHeight;
                    preview.style.fontSize = fontSize + 'px';
                    preview.style.padding = '8px';

                    if (!barcode) {
                        preview.innerHTML =
                            (c.showBusiness ? '<div style="font-weight:800">' + escapeHtml(businessName) + '</div>' : '') +
                            (c.showProduct ? '<div class="label-product" style="font-size:' + (fontSize + 2) + 'px">' + escapeHtml(product.product_name) + '</div>' : '') +
                            '<div class="preview-empty"><i class="fa-solid fa-barcode fa-2x mb-2"></i><div>Type, scan, or generate a barcode.</div></div>' +
                            (c.showProductCode ? '<div class="label-code">' + escapeHtml(product.product_code) + '</div>' : '');

                        if (showError) showToast('error', 'Enter, scan, or generate a barcode first.');
                        return;
                    }

                    const detailParts = [];
                    if (c.showProductCode) detailParts.push(escapeHtml(product.product_code));
                    if (c.showPrice) detailParts.push(escapeHtml(currencySymbol) + Number(product.sale_rate || 0).toFixed(2));
                    if (c.showWeight) detailParts.push(Number(product.net_weight || 0).toFixed(3) + ' g');

                    preview.innerHTML =
                        (c.showBusiness ? '<div style="font-weight:800;font-size:' + (fontSize + 1) + 'px">' + escapeHtml(businessName) + '</div>' : '') +
                        (c.showProduct ? '<div class="label-product" style="font-size:' + (fontSize + 2) + 'px">' + escapeHtml(product.product_name) + '</div>' : '') +
                        '<svg id="barcodeSvg" style="max-width:100%;height:auto"></svg>' +
                        (detailParts.length ? '<div class="label-code" style="font-size:' + fontSize + 'px">' + detailParts.join(' · ') + '</div>' : '') +
                        '<div class="product-sub mt-1" style="font-size:' + Math.max(7, fontSize - 1) + 'px">Sticker Qty: ' + product.qty + '</div>';

                    if (typeof window.JsBarcode !== 'function') {
                        preview.innerHTML = '<div class="preview-empty">Barcode library did not load. Check your internet/CDN access.</div><div class="label-code">' + escapeHtml(barcode) + '</div>';
                        return;
                    }

                    try {
                        window.JsBarcode('#barcodeSvg', barcode, {
                            format: 'CODE128',
                            width: lineWidth,
                            height: barcodeHeight,
                            displayValue: !!c.showBarcodeText,
                            fontSize: fontSize,
                            margin: 2
                        });
                    } catch (error) {
                        preview.innerHTML = '<div class="preview-empty">Unable to render this barcode value.</div><div class="label-code">' + escapeHtml(barcode) + '</div>';
                        if (showError) showToast('error', 'Unable to render the barcode.');
                    }
                }

                body.addEventListener('click', event => {
                    const rowEl = event.target.closest('tr[data-id]');
                    if (!rowEl) return;
                    if (event.target.closest('.generate-one')) { generateForRow(rowEl); return; }
                    if (event.target.closest('.preview-one') || event.target.closest('.main-cell')) previewRow(rowEl, false);
                });
                body.addEventListener('change', event => {
                    const rowEl = event.target.closest('tr[data-id]');
                    if (event.target.classList.contains('row-check')) {
                        updateSelected();
                        if (event.target.checked && rowEl) previewRow(rowEl, false);
                    }
                    if (event.target.classList.contains('qty-input') && rowEl) previewRow(rowEl, false);
                });
                body.addEventListener('input', event => {
                    const rowEl = event.target.closest('tr[data-id]');
                    if (!rowEl) return;
                    if (event.target.classList.contains('barcode-value')) {
                        rowEl.querySelector('.row-check').checked = true;
                        updateSelected();
                        previewRow(rowEl, false);
                    }
                    if (event.target.classList.contains('qty-input')) previewRow(rowEl, false);
                });
                body.addEventListener('focusin', event => {
                    const rowEl = event.target.closest('tr[data-id]');
                    if (rowEl && (event.target.classList.contains('barcode-value') || event.target.classList.contains('qty-input'))) previewRow(rowEl, false);
                });
                checkAll.addEventListener('change', () => { body.querySelectorAll('.row-check').forEach(box => box.checked = checkAll.checked); updateSelected(); });
                document.getElementById('selectVisible').addEventListener('click', () => { body.querySelectorAll('.row-check').forEach(box => box.checked = true); updateSelected(); });
                document.getElementById('generateSelected').addEventListener('click', async () => {
                    const rows = [...body.querySelectorAll('tr[data-id]')].filter(row => row.querySelector('.row-check').checked);
                    if (!rows.length) { showToast('error', 'Select at least one product.'); return; }
                    setLoading(true);
                    try {
                        for (const rowEl of rows) {
                            const result = await generateBarcode(Number(rowEl.dataset.id));
                            const input = rowEl.querySelector('.barcode-value');
                            const badge = rowEl.querySelector('.status-badge');
                            input.value = result.barcode;
                            const product = products.find(item => Number(item.id) === Number(rowEl.dataset.id));
                            if (product) product.barcode = result.barcode;
                            if (badge) { badge.className = 'status-badge status-existing'; badge.textContent = 'Saved'; }
                        }
                        previewRow(rows[0], false);
                        showToast('success', rows.length + ' barcode(s) generated and saved immediately.');
                    } catch (error) { showToast('error', error.message) } finally { setLoading(false) }
                });
                document.getElementById('saveBarcodes').addEventListener('click', async () => { const rows = [...body.querySelectorAll('tr[data-id]')].filter(row => row.querySelector('.row-check').checked); if (!rows.length) { showToast('error', 'Select at least one product.'); return; } const items = rows.map(row => ({ product_id: Number(row.dataset.id), barcode: row.querySelector('.barcode-value').value.trim() })); if (items.some(item => !item.barcode)) { showToast('error', 'Every selected product must have a barcode.'); return; } const data = new FormData(); data.append('action', 'save'); data.append('items', JSON.stringify(items)); setLoading(true); try { const result = await request(data); showToast('success', result.message); await load(); } catch (error) { showToast('error', error.message) } finally { setLoading(false) } });
                document.getElementById('resetFilters').addEventListener('click', () => { search.value = ''; status.value = 'missing'; load(); search.focus(); });
                search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(load, 350) }); status.addEventListener('change', load);
                document.getElementById('copyBarcode').addEventListener('click', async () => { if (!previewData) { showToast('error', 'Preview a product first.'); return; } try { await navigator.clipboard.writeText(previewData.barcode); showToast('success', 'Barcode copied.') } catch (error) { showToast('error', 'Unable to copy barcode.') } });
                const settingIds = ['labelWidth', 'labelHeight', 'labelsPerRow', 'horizontalGap', 'verticalGap', 'pageMargin', 'barcodeHeight', 'barcodeLineWidth', 'labelFontSize', 'printOrientation', 'showBusiness', 'showProduct', 'showProductCode', 'showPrice', 'showWeight', 'showBarcodeText'];
                function cfg() {
                    const o = {};
                    settingIds.forEach(id => {
                        const el = document.getElementById(id);
                        o[id] = el.type === 'checkbox' ? el.checked : el.value;
                    });
                    return o;
                }
                function loadCfg() {
                    try {
                        const o = JSON.parse(localStorage.getItem('barcodePrinterSettings') || '{}');
                        settingIds.forEach(id => {
                            const el = document.getElementById(id);
                            if (o[id] !== undefined) {
                                if (el.type === 'checkbox') el.checked = !!o[id];
                                else el.value = o[id];
                            }
                        });
                    } catch (error) {}
                }
                settingIds.forEach(id => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    const eventName = el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input';
                    el.addEventListener(eventName, () => renderLivePreview(false));
                });
                document.getElementById('savePrinterSettings').addEventListener('click', () => {
                    localStorage.setItem('barcodePrinterSettings', JSON.stringify(cfg()));
                    renderLivePreview(false);
                    showToast('success', 'Printer settings saved.');
                });
                function selectedItems() { return [...body.querySelectorAll('tr[data-id]')].filter(r => r.querySelector('.row-check').checked).map(r => { const p = products.find(x => Number(x.id) === Number(r.dataset.id)); return p ? { ...p, barcode: r.querySelector('.barcode-value').value.trim(), qty: Math.min(500, Math.max(1, Number(r.querySelector('.qty-input').value || 1))) } : null }).filter(Boolean) } function buildPrint(items) { const c = cfg(), sheet = document.getElementById('printSheet'); sheet.innerHTML = ''; document.getElementById('dynamicPrintStyle')?.remove(); const st = document.createElement('style'); st.id = 'dynamicPrintStyle'; st.textContent = '@media print{@page{size:' + c.printOrientation + ';margin:' + Number(c.pageMargin) + 'mm}#printSheet{display:grid!important;grid-template-columns:repeat(' + Math.max(1, Number(c.labelsPerRow)) + ',' + Number(c.labelWidth) + 'mm);gap:' + Number(c.verticalGap) + 'mm ' + Number(c.horizontalGap) + 'mm;width:max-content}.print-label{width:' + Number(c.labelWidth) + 'mm;height:' + Number(c.labelHeight) + 'mm;padding:1.5mm;font-size:' + Number(c.labelFontSize) + 'px}}'; document.head.appendChild(st); items.forEach(it => { for (let n = 0; n < it.qty; n++) { const d = document.createElement('div'); d.className = 'print-label'; d.innerHTML = (c.showBusiness ? '<div><b>' + escapeHtml(businessName) + '</b></div>' : '') + (c.showProduct ? '<div class="pl-product">' + escapeHtml(it.product_name) + '</div>' : '') + '<svg></svg><div>' + (c.showProductCode ? escapeHtml(it.product_code) + ' ' : '') + (c.showPrice ? currencySymbol + Number(it.sale_rate || 0).toFixed(2) + ' ' : '') + (c.showWeight ? Number(it.net_weight || 0).toFixed(3) + 'g' : '') + '</div>'; sheet.appendChild(d); JsBarcode(d.querySelector('svg'), it.barcode, { format: 'CODE128', width: Number(c.barcodeLineWidth), height: Number(c.barcodeHeight), displayValue: !!c.showBarcodeText, fontSize: Math.max(7, Number(c.labelFontSize)), margin: 1 }) } }) } document.getElementById('printBarcode').addEventListener('click', () => { const items = selectedItems(); if (!items.length && previewData) items.push(previewData); if (!items.length) { showToast('error', 'Select at least one product.'); return } if (items.some(x => !x.barcode)) { showToast('error', 'Every selected product needs a barcode.'); return } buildPrint(items); window.print() });
                loadCfg();
                renderLivePreview(false);
                load();
            })();
        </script>
    <?php endif; ?>
</body>

</html>