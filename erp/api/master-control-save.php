<?php
if (session_status() === PHP_SESSION_NONE)
  session_start();
header('Content-Type: application/json; charset=utf-8');
foreach ([dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php'] as $f) {
  if (is_file($f)) {
    require_once $f;
    break;
  }
}
function out(bool $ok, string $message, array $extra = [], int $status = 200): void
{
  http_response_code($status);
  echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if (!isset($conn) || !($conn instanceof mysqli))
  out(false, 'Database configuration is not available.', [], 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
  out(false, 'Session expired.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
  out(false, 'Invalid request method.', [], 405);
if (!hash_equals((string) ($_SESSION['master_control_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
  out(false, 'Invalid request token.', [], 419);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) $_SESSION['user_id'];
if ($businessId <= 0 || $branchId <= 0)
  out(false, 'Select a valid business and branch.', [], 403);
$action = (string) ($_POST['action'] ?? '');
try {
  if ($action === 'save_number') {
    $key = (string) ($_POST['document_key'] ?? '');
    if (!in_array($key, ['invoice', 'purchase', 'pawn', 'chit'], true))
      throw new RuntimeException('Invalid document type.');
    $prefix = trim((string) ($_POST['prefix'] ?? ''));
    $center = trim((string) ($_POST['center_format'] ?? ''));
    $suffix = trim((string) ($_POST['suffix'] ?? ''));
    $divider = (string) ($_POST['divider'] ?? '');
    $digits = max(1, min(12, (int) ($_POST['sequence_digits'] ?? 4)));
    $start = max(1, (int) ($_POST['sequence_start'] ?? 1));
    $reset = (string) ($_POST['reset_frequency'] ?? 'Financial Year');
    if (!in_array($reset, ['Never', 'Financial Year', 'Calendar Year', 'Monthly', 'Daily'], true))
      $reset = 'Financial Year';
    $template = trim((string) ($_POST['format_template'] ?? '{PREFIX}{DIVIDER}{CENTER}{DIVIDER}{SEQ}{SUFFIX}'));
    if (strpos($template, '{SEQ}') === false)
      throw new RuntimeException('Format template must contain {SEQ}.');
    require_once dirname(__DIR__) . '/includes/document-number-helper.php';
    $sampleCenter = documentNumberCenter($center, date('Y-m-d'));
    $sample = strtr($template, ['{PREFIX}' => $prefix, '{DIVIDER}' => $divider, '{CENTER}' => $sampleCenter, '{SEQ}' => str_pad((string) $start, $digits, '0', STR_PAD_LEFT), '{SUFFIX}' => $suffix]);
    $conn->begin_transaction();
    try {
      $settingName = ucfirst($key) . ' Number';
      $stmt = $conn->prepare("UPDATE document_number_settings SET setting_name=?,prefix=?,center_format=?,suffix=?,divider=?,sequence_digits=?,sequence_start=?,reset_frequency=?,format_template=?,sample_output=?,is_active=1,updated_at=CURRENT_TIMESTAMP WHERE business_id=? AND branch_id=? AND document_key=?");
      if (!$stmt)
        throw new RuntimeException($conn->error);
      $stmt->bind_param('sssssiisssiis', $settingName, $prefix, $center, $suffix, $divider, $digits, $start, $reset, $template, $sample, $businessId, $branchId, $key);
      if (!$stmt->execute())
        throw new RuntimeException($stmt->error);
      $matched = $stmt->affected_rows;
      $stmt->close();

      if ($matched === 0) {
        $check = $conn->prepare('SELECT id FROM document_number_settings WHERE business_id=? AND branch_id=? AND document_key=? LIMIT 1');
        if (!$check)
          throw new RuntimeException($conn->error);
        $check->bind_param('iis', $businessId, $branchId, $key);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) {
          $stmt = $conn->prepare("INSERT INTO document_number_settings (business_id,branch_id,document_key,setting_name,prefix,center_format,suffix,divider,sequence_digits,sequence_start,reset_frequency,format_template,sample_output,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
          if (!$stmt)
            throw new RuntimeException($conn->error);
          $stmt->bind_param('iissssssiisss', $businessId, $branchId, $key, $settingName, $prefix, $center, $suffix, $divider, $digits, $start, $reset, $template, $sample);
          if (!$stmt->execute())
            throw new RuntimeException($stmt->error);
          $stmt->close();
        }
      }

      $cleanup = $conn->prepare('DELETE d1 FROM document_number_settings d1 INNER JOIN document_number_settings d2 ON d1.business_id=d2.business_id AND d1.branch_id=d2.branch_id AND d1.document_key=d2.document_key AND d1.id<d2.id WHERE d1.business_id=? AND d1.branch_id=? AND d1.document_key=?');
      if ($cleanup) {
        $cleanup->bind_param('iis', $businessId, $branchId, $key);
        $cleanup->execute();
        $cleanup->close();
      }
      $conn->commit();
    } catch (Throwable $saveError) {
      $conn->rollback();
      throw $saveError;
    }
    out(true, 'Numbering setting saved successfully.', ['sample_output' => $sample]);
  }
  if ($action === 'save_metal') {
    $id = (int) ($_POST['metal_id'] ?? 0);
    $code = strtoupper(trim((string) ($_POST['metal_code'] ?? '')));
    $name = trim((string) ($_POST['metal_name'] ?? ''));
    $purity = (float) ($_POST['default_purity'] ?? 0);
    $active = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
    if ($code === '' || $name === '')
      throw new RuntimeException('Metal code and metal name are required.');
    if ($purity < 0 || $purity > 100)
      throw new RuntimeException('Purity must be between 0 and 100.');
    if ($id > 0) {
      $stmt = $conn->prepare('UPDATE metals SET metal_code=?,metal_name=?,default_purity=?,is_active=? WHERE id=? AND business_id=? LIMIT 1');
      $stmt->bind_param('ssdiii', $code, $name, $purity, $active, $id, $businessId);
    } else {
      $stmt = $conn->prepare('INSERT INTO metals (business_id,metal_code,metal_name,default_purity,is_active) VALUES (?,?,?,?,?)');
      $stmt->bind_param('issdi', $businessId, $code, $name, $purity, $active);
    }
    if (!$stmt || !$stmt->execute())
      throw new RuntimeException($stmt ? $stmt->error : $conn->error);
    $newId = $id ?: $stmt->insert_id;
    $stmt->close();
    out(true, 'Metal type saved successfully.', ['metal_id' => $newId]);
  }
  if ($action === 'toggle_metal') {
    $id = (int) ($_POST['metal_id'] ?? 0);
    $stmt = $conn->prepare('UPDATE metals SET is_active=IF(is_active=1,0,1) WHERE id=? AND business_id=?');
    $stmt->bind_param('ii', $id, $businessId);
    if (!$stmt->execute())
      throw new RuntimeException($stmt->error);
    $stmt->close();
    out(true, 'Metal status changed successfully.');
  }
  if ($action === 'delete_metal') {
    $id = (int) ($_POST['metal_id'] ?? 0);
    $stmt = $conn->prepare('SELECT COUNT(*) c FROM products WHERE metal_id=? AND business_id=?');
    $stmt->bind_param('ii', $id, $businessId);
    $stmt->execute();
    $used = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($used > 0)
      throw new RuntimeException('This metal is used by products and cannot be deleted. Deactivate it instead.');
    $stmt = $conn->prepare('DELETE FROM metals WHERE id=? AND business_id=?');
    $stmt->bind_param('ii', $id, $businessId);
    if (!$stmt->execute())
      throw new RuntimeException($stmt->error);
    $stmt->close();
    out(true, 'Metal deleted successfully.');
  }
  out(false, 'Invalid action.', [], 400);
} catch (Throwable $e) {
  out(false, $e->getMessage(), [], 500);
}