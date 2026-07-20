<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: application/json; charset=utf-8');
function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
foreach ([dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    respond(false, 'Database configuration is not available.', [], 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    respond(false, 'Your session has expired. Please log in again.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    respond(false, 'Invalid request method.', [], 405);
if (!hash_equals((string) ($_SESSION['products_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
function permission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'value' => 'can_view_value',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    // Always prefer the product-list permission before the parent Products permission.
    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.products.list', 'perm.products'] as $permissionCode) {
        if (isset($permissions[$permissionCode]) && array_key_exists($field, $permissions[$permissionCode])) {
            return (int) $permissions[$permissionCode][$field] === 1;
        }
    }

    // Database fallback, matching manage-product.php.
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
              AND p.permission_code IN ('perm.products.list','perm.products')
            ORDER BY FIELD(p.permission_code,'perm.products.list','perm.products')
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
function audit(mysqli $conn, int $businessId, int $branchId, int $userId, string $action, int $id, string $description, $old = null, $new = null): void
{
    $stmt = $conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent) VALUES (?,?,?,'products.products',?,'products',?,?,?,?,?,?,?)");
    if (!$stmt)
        return;
    $oldJson = $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $stmt->bind_param('iiisssssss', $businessId, $branchId, $userId, $action, $id, $description, $oldJson, $newJson, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}
function uploadImage(string $field, int $businessId): array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
        return ['ok' => true, 'path' => null];
    $f = $_FILES[$field];
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK)
        return ['ok' => false, 'message' => 'Image upload failed.'];
    if (($f['size'] ?? 0) > 2 * 1024 * 1024)
        return ['ok' => false, 'message' => 'Image size must be below 2 MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime]))
        return ['ok' => false, 'message' => 'Only JPG, PNG, WEBP and GIF images are allowed.'];
    $dir = dirname(__DIR__) . '/uploads/business/' . $businessId . '/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir))
        return ['ok' => false, 'message' => 'Unable to create product upload folder.'];
    $name = 'product_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $full = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $full))
        return ['ok' => false, 'message' => 'Unable to save product image.'];
    return ['ok' => true, 'path' => 'uploads/business/' . $businessId . '/products/' . $name];
}
$action = (string) ($_POST['action'] ?? '');
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($businessId <= 0)
    respond(false, 'A valid business must be selected.', [], 403);

if ($action === 'list') {
    if (!permission($conn, 'view') && !permission($conn, 'open'))
        respond(false, 'You do not have permission to view products.', [], 403);

    $search = trim((string) ($_POST['search'] ?? ''));
    $categoryId = max(0, (int) ($_POST['category_id'] ?? 0));
    $status = (string) ($_POST['status'] ?? '');
    if (!in_array($status, ['', 'active', 'inactive'], true))
        $status = '';
    $allowedPerPage = [10, 25, 50, 100];
    $perPage = (int) ($_POST['per_page'] ?? 10);
    if (!in_array($perPage, $allowedPerPage, true))
        $perPage = 10;
    $page = max(1, (int) ($_POST['page'] ?? 1));

    $where = ' WHERE p.business_id=?';
    $types = 'i';
    $params = [$businessId];

    if ($search !== '') {
        $where .= ' AND (p.product_name LIKE ? OR p.product_code LIKE ? OR COALESCE(p.barcode,\'\') LIKE ? OR COALESCE(c.category_name,\'\') LIKE ? OR COALESCE(m.metal_name,\'\') LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }
    if ($categoryId > 0) {
        $where .= ' AND p.category_id=?';
        $params[] = $categoryId;
        $types .= 'i';
    }
    if ($status === 'active')
        $where .= ' AND p.is_active=1';
    elseif ($status === 'inactive')
        $where .= ' AND p.is_active=0';

    $countSql = 'SELECT COUNT(DISTINCT p.id) AS total FROM products p LEFT JOIN product_categories c ON c.id=p.category_id AND c.business_id=p.business_id LEFT JOIN metals m ON m.id=p.metal_id AND m.business_id=p.business_id' . $where;
    $stmt = $conn->prepare($countSql);
    if (!$stmt)
        respond(false, 'Unable to prepare product count query.', [], 500);
    $bind = [$types];
    foreach ($params as $k => $v)
        $bind[] =& $params[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!$stmt->execute())
        respond(false, 'Unable to execute product count query: ' . $stmt->error, [], 500);
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages)
        $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT p.id,p.product_code,p.barcode,p.product_name,p.purity,p.gross_weight,p.net_weight,p.purchase_rate,p.sale_rate,p.image_path,p.is_active,p.track_stock,c.category_name,m.metal_name,u.unit_name,u.decimal_places,COALESCE((SELECT SUM(ps.quantity) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_qty,COALESCE((SELECT SUM(ps.gross_weight) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_gross_weight,COALESCE((SELECT SUM(ps.net_weight) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_net_weight,COALESCE((SELECT SUM(ps.stock_value) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_value,COALESCE((SELECT AVG(ps.average_cost) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS average_cost,
COALESCE((SELECT SUM(ps.stock_value) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_value FROM products p LEFT JOIN product_categories c ON c.id=p.category_id AND c.business_id=p.business_id LEFT JOIN metals m ON m.id=p.metal_id AND m.business_id=p.business_id LEFT JOIN units u ON u.id=p.unit_id AND u.business_id=p.business_id' . $where . ' ORDER BY p.id DESC LIMIT ? OFFSET ?';
    $listParams = $params;
    $listTypes = $types . 'ii';
    $listParams[] = $perPage;
    $listParams[] = $offset;
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        respond(false, 'Unable to prepare product list query.', [], 500);
    $bind = [$listTypes];
    foreach ($listParams as $k => $v)
        $bind[] =& $listParams[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!$stmt->execute())
        respond(false, 'Unable to execute product list query: ' . $stmt->error, [], 500);
    $result = $stmt->get_result();
    $products = [];
    while ($row = $result->fetch_assoc())
        $products[] = $row;
    $stmt->close();

    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'tracked' => 0];
    $stmt = $conn->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(is_active=1),0) AS active,COALESCE(SUM(is_active=0),0) AS inactive,COALESCE(SUM(track_stock=1),0) AS tracked FROM products WHERE business_id=?');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $stats = array_merge($stats, $stmt->get_result()->fetch_assoc() ?: []);
        $stmt->close();
    }

    $from = $total > 0 ? $offset + 1 : 0;
    $to = $total > 0 ? min($offset + $perPage, $total) : 0;
    respond(true, 'Products loaded.', [
        'products' => $products,
        'stats' => $stats,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $from,
            'to' => $to,
        ],
    ]);
}

if ($action === 'view') {
    if (!permission($conn, 'view') && !permission($conn, 'open'))
        respond(false, 'You do not have permission to view products.', [], 403);
    $id = (int) ($_POST['product_id'] ?? 0);
    if ($id <= 0)
        respond(false, 'Invalid product ID.', [], 422);

    $sql = 'SELECT p.*,c.category_name,m.metal_name,u.unit_code,u.unit_name,u.decimal_places,COALESCE((SELECT SUM(ps.quantity) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_qty,COALESCE((SELECT SUM(ps.gross_weight) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_gross_weight,COALESCE((SELECT SUM(ps.net_weight) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_net_weight,COALESCE((SELECT SUM(ps.stock_value) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS stock_value,COALESCE((SELECT AVG(ps.average_cost) FROM product_stock ps WHERE ps.product_id=p.id AND ps.business_id=p.business_id),0) AS average_cost FROM products p LEFT JOIN product_categories c ON c.id=p.category_id AND c.business_id=p.business_id LEFT JOIN metals m ON m.id=p.metal_id AND m.business_id=p.business_id LEFT JOIN units u ON u.id=p.unit_id AND u.business_id=p.business_id WHERE p.id=? AND p.business_id=? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        respond(false, 'Unable to prepare product detail query.', [], 500);
    $stmt->bind_param('ii', $id, $businessId);
    if (!$stmt->execute())
        respond(false, 'Unable to load product details: ' . $stmt->error, [], 500);
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$product)
        respond(false, 'Product not found.', [], 404);

    if (!permission($conn, 'value')) {
        $product['purchase_rate'] = null;
        $product['sale_rate'] = null;
    }

    respond(true, 'Product details loaded.', ['product' => $product]);
}

if ($action === 'save') {
    $id = (int) ($_POST['product_id'] ?? 0);
    $isNew = $id <= 0;
    if ($isNew && !permission($conn, 'create'))
        respond(false, 'You do not have permission to create products.', [], 403);
    if (!$isNew && !permission($conn, 'update'))
        respond(false, 'You do not have permission to update products.', [], 403);
    $data = ['category_id' => (int) ($_POST['category_id'] ?? 0), 'metal_id' => (int) ($_POST['metal_id'] ?? 0), 'unit_id' => (int) ($_POST['unit_id'] ?? 0), 'product_code' => strtoupper(trim((string) ($_POST['product_code'] ?? ''))), 'barcode' => trim((string) ($_POST['barcode'] ?? '')), 'product_name' => trim((string) ($_POST['product_name'] ?? '')), 'hsn_code' => trim((string) ($_POST['hsn_code'] ?? '')), 'purity' => (float) ($_POST['purity'] ?? 0), 'gross_weight' => (float) ($_POST['gross_weight'] ?? 0), 'stone_weight' => (float) ($_POST['stone_weight'] ?? 0), 'net_weight' => (float) ($_POST['net_weight'] ?? 0), 'wastage_percent' => (float) ($_POST['wastage_percent'] ?? 0), 'making_charge_type' => (string) ($_POST['making_charge_type'] ?? 'Per Gram'), 'making_charge' => (float) ($_POST['making_charge'] ?? 0), 'purchase_rate' => (float) ($_POST['purchase_rate'] ?? 0), 'sale_rate' => (float) ($_POST['sale_rate'] ?? 0), 'tax_percent' => (float) ($_POST['tax_percent'] ?? 3), 'minimum_stock_qty' => (float) ($_POST['minimum_stock_qty'] ?? 0), 'track_stock' => isset($_POST['track_stock']) ? 1 : 0, 'description' => trim((string) ($_POST['description'] ?? '')), 'is_active' => isset($_POST['is_active']) ? 1 : 0];
    if ($data['category_id'] <= 0)
        respond(false, 'Please select a category.');
    if ($data['product_name'] === '')
        respond(false, 'Product name is required.');
    if (!in_array($data['making_charge_type'], ['Per Gram', 'Fixed', 'Percentage'], true))
        respond(false, 'Invalid making charge type.');
    foreach (['purity', 'gross_weight', 'stone_weight', 'net_weight', 'wastage_percent', 'making_charge', 'purchase_rate', 'sale_rate', 'tax_percent', 'minimum_stock_qty'] as $k)
        if ($data[$k] < 0)
            respond(false, ucwords(str_replace('_', ' ', $k)) . ' cannot be negative.');
    $stmt = $conn->prepare('SELECT id FROM product_categories WHERE id=? AND business_id=? AND is_active=1');
    $stmt->bind_param('ii', $data['category_id'], $businessId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc())
        respond(false, 'Selected category is invalid or inactive.');
    $stmt->close();
    if ($data['unit_id'] <= 0)
        respond(false, 'Please select a unit.');
    $stmt = $conn->prepare('SELECT id FROM units WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
    if (!$stmt)
        respond(false, 'Unable to validate selected unit.', [], 500);
    $stmt->bind_param('ii', $data['unit_id'], $businessId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc())
        respond(false, 'Selected unit is invalid or inactive.');
    $stmt->close();
    if ($data['product_code'] === '') {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(id),0)+1 next_id FROM products WHERE business_id=?");
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $next = (int) ($stmt->get_result()->fetch_assoc()['next_id'] ?? 1);
        $stmt->close();
        $data['product_code'] = 'PRD' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
    $stmt = $conn->prepare('SELECT id FROM products WHERE business_id=? AND product_code=? AND id<>? LIMIT 1');
    $stmt->bind_param('isi', $businessId, $data['product_code'], $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc())
        respond(false, 'This product code is already used.');
    $stmt->close();
    if ($data['barcode'] !== '') {
        $stmt = $conn->prepare('SELECT id FROM products WHERE business_id=? AND barcode=? AND id<>? LIMIT 1');
        $stmt->bind_param('isi', $businessId, $data['barcode'], $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc())
            respond(false, 'This barcode is already used.');
        $stmt->close();
    }
    $old = null;
    $oldImage = null;
    if (!$isNew) {
        $stmt = $conn->prepare('SELECT * FROM products WHERE id=? AND business_id=? LIMIT 1');
        $stmt->bind_param('ii', $id, $businessId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$old)
            respond(false, 'Product not found.', [], 404);
        $oldImage = $old['image_path'] ?? null;
    }
    $up = uploadImage('image', $businessId);
    if (!$up['ok'])
        respond(false, $up['message']);
    $image = $up['path'] ?? $oldImage;
    $barcode = $data['barcode'] === '' ? null : $data['barcode'];
    $hsn = $data['hsn_code'] === '' ? null : $data['hsn_code'];
    $metal = $data['metal_id'] > 0 ? $data['metal_id'] : null;
    $unit = $data['unit_id'] > 0 ? $data['unit_id'] : null;
    $desc = $data['description'] === '' ? null : $data['description'];
    if ($isNew) {
        $stmt = $conn->prepare('INSERT INTO products (business_id,category_id,metal_id,unit_id,product_code,barcode,product_name,hsn_code,purity,gross_weight,stone_weight,net_weight,wastage_percent,making_charge_type,making_charge,purchase_rate,sale_rate,tax_percent,minimum_stock_qty,track_stock,image_path,description,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('iiiissssdddddsdddddissi', $businessId, $data['category_id'], $metal, $unit, $data['product_code'], $barcode, $data['product_name'], $hsn, $data['purity'], $data['gross_weight'], $data['stone_weight'], $data['net_weight'], $data['wastage_percent'], $data['making_charge_type'], $data['making_charge'], $data['purchase_rate'], $data['sale_rate'], $data['tax_percent'], $data['minimum_stock_qty'], $data['track_stock'], $image, $desc, $data['is_active']);
        if (!$stmt->execute())
            respond(false, 'Unable to create product: ' . $stmt->error, [], 500);
        $id = (int) $stmt->insert_id;
        $stmt->close();
    } else {
        $stmt = $conn->prepare('UPDATE products SET category_id=?,metal_id=?,unit_id=?,product_code=?,barcode=?,product_name=?,hsn_code=?,purity=?,gross_weight=?,stone_weight=?,net_weight=?,wastage_percent=?,making_charge_type=?,making_charge=?,purchase_rate=?,sale_rate=?,tax_percent=?,minimum_stock_qty=?,track_stock=?,image_path=?,description=?,is_active=? WHERE id=? AND business_id=?');
        $stmt->bind_param('iiissssdddddsdddddissiii', $data['category_id'], $metal, $unit, $data['product_code'], $barcode, $data['product_name'], $hsn, $data['purity'], $data['gross_weight'], $data['stone_weight'], $data['net_weight'], $data['wastage_percent'], $data['making_charge_type'], $data['making_charge'], $data['purchase_rate'], $data['sale_rate'], $data['tax_percent'], $data['minimum_stock_qty'], $data['track_stock'], $image, $desc, $data['is_active'], $id, $businessId);
        if (!$stmt->execute())
            respond(false, 'Unable to update product: ' . $stmt->error, [], 500);
        $stmt->close();
    }
    $new = $data;
    $new['image_path'] = $image;
    audit($conn, $businessId, $branchId, $userId, $isNew ? 'Create' : 'Update', $id, ($isNew ? 'Created' : 'Updated') . ' product ' . $data['product_name'], $old, $new);
    respond(true, $isNew ? 'Product created successfully.' : 'Product updated successfully.', ['product_id' => $id]);
}
if ($action === 'toggle') {
    if (!permission($conn, 'update'))
        respond(false, 'You do not have permission to update products.', [], 403);
    $id = (int) ($_POST['product_id'] ?? 0);
    $active = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $stmt = $conn->prepare('UPDATE products SET is_active=? WHERE id=? AND business_id=?');
    $stmt->bind_param('iii', $active, $id, $businessId);
    $stmt->execute();
    if ($stmt->affected_rows < 1)
        respond(false, 'Product not found or status unchanged.');
    $stmt->close();
    audit($conn, $businessId, $branchId, $userId, 'Update', $id, $active ? 'Activated product' : 'Deactivated product', null, ['is_active' => $active]);
    respond(true, $active ? 'Product activated successfully.' : 'Product deactivated successfully.');
}
if ($action === 'delete') {
    if (!permission($conn, 'delete'))
        respond(false, 'You do not have permission to delete products.', [], 403);
    $id = (int) ($_POST['product_id'] ?? 0);
    $stmt = $conn->prepare('SELECT * FROM products WHERE id=? AND business_id=?');
    $stmt->bind_param('ii', $id, $businessId);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old)
        respond(false, 'Product not found.', [], 404);
    foreach ([['sale_items', 'product_id'], ['purchase_items', 'product_id'], ['product_stock', 'product_id'], ['branch_transfer_items', 'product_id']] as $ref) {
        $check = $conn->query("SHOW TABLES LIKE '{$ref[0]}'");
        if ($check && $check->num_rows) {
            $stmt = $conn->prepare("SELECT COUNT(*) total FROM {$ref[0]} WHERE {$ref[1]}=? AND business_id=?");
            $stmt->bind_param('ii', $id, $businessId);
            $stmt->execute();
            $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            if ($count > 0)
                respond(false, 'This product is linked to existing transactions or stock. Deactivate it instead.', [], 409);
        }
    }
    $stmt = $conn->prepare('DELETE FROM products WHERE id=? AND business_id=?');
    $stmt->bind_param('ii', $id, $businessId);
    $stmt->execute();
    if ($stmt->affected_rows < 1)
        respond(false, 'Product could not be deleted.', [], 400);
    $stmt->close();
    audit($conn, $businessId, $branchId, $userId, 'Delete', $id, 'Deleted product ' . $old['product_name'], $old, null);
    respond(true, 'Product deleted successfully.');
}
respond(false, 'Invalid action.', [], 400);