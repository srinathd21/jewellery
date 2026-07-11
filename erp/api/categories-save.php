<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}
if (!hash_equals((string)($_SESSION['categories_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];
    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.products.categories', 'perm.products'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.products.categories','perm.products')
            ORDER BY FIELD(p.permission_code,'perm.products.categories','perm.products')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

function audit(mysqli $conn, int $businessId, int $branchId, int $userId, string $action, ?int $referenceId, string $description, $oldValues = null, $newValues = null): void
{
    $check = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if (!$check || $check->num_rows === 0) {
        return;
    }

    $oldJson = $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $conn->prepare("INSERT INTO audit_logs
        (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, old_values_json, new_values_json, ip_address, user_agent)
        VALUES (?, ?, ?, 'products.categories', ?, 'product_categories', ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('iiisssssss', $businessId, $branchId, $userId, $action, $referenceId, $description, $oldJson, $newJson, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    }
}

$action = (string)($_POST['action'] ?? '');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentBranchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if ($action === 'get') {
    if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
        respond(false, 'You do not have permission to view categories.', [], 403);
    }

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $stmt = $conn->prepare('SELECT id, parent_id, category_code, category_name, description, sort_order, is_active FROM product_categories WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $categoryId, $businessId);
    $stmt->execute();
    $category = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$category) {
        respond(false, 'Category not found.', [], 404);
    }
    respond(true, 'Category loaded.', ['category' => $category]);
}

if ($action === 'save') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $isNew = $categoryId <= 0;

    if ($isNew && !hasPermission($conn, 'create')) {
        respond(false, 'You do not have permission to create categories.', [], 403);
    }
    if (!$isNew && !hasPermission($conn, 'update')) {
        respond(false, 'You do not have permission to update categories.', [], 403);
    }

    $categoryName = trim((string)($_POST['category_name'] ?? ''));
    $categoryCode = strtoupper(trim((string)($_POST['category_code'] ?? '')));
    $description = trim((string)($_POST['description'] ?? ''));
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $sortOrderRaw = trim((string)($_POST['sort_order'] ?? ''));
    $sortOrder = $sortOrderRaw === '' ? 0 : max(0, (int)$sortOrderRaw);
    $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($categoryName === '') {
        respond(false, 'Category name is required.');
    }
    if (mb_strlen($categoryName) > 120) {
        respond(false, 'Category name must not exceed 120 characters.');
    }
    if (mb_strlen($description) > 1000) {
        respond(false, 'Description must not exceed 1000 characters.');
    }
    if ($categoryCode !== '' && !preg_match('/^[A-Z0-9_-]{2,50}$/', $categoryCode)) {
        respond(false, 'Category code may contain only letters, numbers, underscore and hyphen.');
    }
    if ($parentId === $categoryId && $categoryId > 0) {
        respond(false, 'A category cannot be its own parent.');
    }

    if ($parentId > 0) {
        $stmt = $conn->prepare('SELECT id, parent_id FROM product_categories WHERE id = ? AND business_id = ? LIMIT 1');
        $stmt->bind_param('ii', $parentId, $businessId);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$parent) {
            respond(false, 'Selected parent category is invalid.');
        }
        if (!$isNew && (int)($parent['parent_id'] ?? 0) === $categoryId) {
            respond(false, 'Circular parent category selection is not allowed.');
        }
    }

    if ($categoryCode !== '') {
        $stmt = $conn->prepare('SELECT id FROM product_categories WHERE business_id = ? AND category_code = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('isi', $businessId, $categoryCode, $categoryId);
        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicate) {
            respond(false, 'This category code is already used.');
        }
    }

    $stmt = $conn->prepare('SELECT id FROM product_categories WHERE business_id = ? AND category_name = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('isi', $businessId, $categoryName, $categoryId);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($duplicate) {
        respond(false, 'This category name is already used.');
    }

    $old = null;
    if (!$isNew) {
        $stmt = $conn->prepare('SELECT * FROM product_categories WHERE id = ? AND business_id = ? LIMIT 1');
        $stmt->bind_param('ii', $categoryId, $businessId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$old) {
            respond(false, 'Category not found.', [], 404);
        }
    }

    if ($sortOrderRaw === '') {
        if ($isNew) {
            $stmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort_order FROM product_categories WHERE business_id = ?');
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $sortOrder = (int)($stmt->get_result()->fetch_assoc()['next_sort_order'] ?? 1);
            $stmt->close();
            if ($sortOrder <= 0) {
                $sortOrder = 1;
            }
        } else {
            $sortOrder = (int)($old['sort_order'] ?? 0);
        }
    }

    $parentValue = $parentId > 0 ? $parentId : null;
    $categoryCodeValue = $categoryCode !== '' ? $categoryCode : null;
    $descriptionValue = $description !== '' ? $description : null;
    if ($isNew) {
        $stmt = $conn->prepare('INSERT INTO product_categories (business_id, parent_id, category_code, category_name, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisssii', $businessId, $parentValue, $categoryCodeValue, $categoryName, $descriptionValue, $sortOrder, $isActive);
        if (!$stmt->execute()) {
            respond(false, 'Unable to create category: ' . $stmt->error, [], 500);
        }
        $categoryId = (int)$stmt->insert_id;
        $stmt->close();
    } else {
        $stmt = $conn->prepare('UPDATE product_categories SET parent_id = ?, category_code = ?, category_name = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ? AND business_id = ?');
        $stmt->bind_param('isssiiii', $parentValue, $categoryCodeValue, $categoryName, $descriptionValue, $sortOrder, $isActive, $categoryId, $businessId);
        if (!$stmt->execute()) {
            respond(false, 'Unable to update category: ' . $stmt->error, [], 500);
        }
        $stmt->close();
    }

    $new = [
        'parent_id' => $parentValue,
        'category_code' => $categoryCodeValue,
        'category_name' => $categoryName,
        'description' => $descriptionValue,
        'sort_order' => $sortOrder,
        'is_active' => $isActive,
    ];
    audit($conn, $businessId, $currentBranchId, $currentUserId, $isNew ? 'Create' : 'Update', $categoryId, ($isNew ? 'Created' : 'Updated') . ' category ' . $categoryName, $old, $new);
    respond(true, $isNew ? 'Category created successfully.' : 'Category updated successfully.', ['category_id' => $categoryId]);
}

if ($action === 'toggle') {
    if (!hasPermission($conn, 'update')) {
        respond(false, 'You do not have permission to update categories.', [], 403);
    }

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $stmt = $conn->prepare('UPDATE product_categories SET is_active = ? WHERE id = ? AND business_id = ?');
    $stmt->bind_param('iii', $isActive, $categoryId, $businessId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        respond(false, 'Category not found or status was unchanged.');
    }

    audit($conn, $businessId, $currentBranchId, $currentUserId, 'Update', $categoryId, $isActive ? 'Activated category' : 'Deactivated category', null, ['is_active' => $isActive]);
    respond(true, $isActive ? 'Category activated successfully.' : 'Category deactivated successfully.');
}

if ($action === 'delete') {
    if (!hasPermission($conn, 'delete')) {
        respond(false, 'You do not have permission to delete categories.', [], 403);
    }

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $stmt = $conn->prepare('SELECT * FROM product_categories WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $categoryId, $businessId);
    $stmt->execute();
    $category = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$category) {
        respond(false, 'Category not found.', [], 404);
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM products WHERE category_id = ? AND business_id = ?');
    $stmt->bind_param('ii', $categoryId, $businessId);
    $stmt->execute();
    $productCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    if ($productCount > 0) {
        respond(false, 'This category has ' . $productCount . ' linked product(s). Deactivate it instead.', [], 409);
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM product_categories WHERE parent_id = ? AND business_id = ?');
    $stmt->bind_param('ii', $categoryId, $businessId);
    $stmt->execute();
    $childCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    if ($childCount > 0) {
        respond(false, 'This category has ' . $childCount . ' child category/categories. Reassign them before deleting.', [], 409);
    }

    try {
        $stmt = $conn->prepare('DELETE FROM product_categories WHERE id = ? AND business_id = ?');
        $stmt->bind_param('ii', $categoryId, $businessId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    } catch (Throwable $e) {
        respond(false, 'This category is linked to existing records and cannot be deleted. Deactivate it instead.', [], 409);
    }

    if ($affected < 1) {
        respond(false, 'Category could not be deleted.', [], 400);
    }

    audit($conn, $businessId, $currentBranchId, $currentUserId, 'Delete', $categoryId, 'Deleted category ' . $category['category_name'], $category, null);
    respond(true, 'Category deleted successfully.');
}

respond(false, 'Invalid action.', [], 400);
