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
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    }
}

function addAuditLogSafe(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, int $refId, string $desc): void
{
    if (function_exists('addAuditLog')) {
        addAuditLog($conn, $businessId, $userId, $module, $action, $refId, $desc);
        return;
    }

    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $sql = "INSERT INTO audit_logs (
                business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param(
        'iississs',
        $businessId,
        $userId,
        $module,
        $action,
        $refId,
        $desc,
        $ip,
        $ua
    );
    $stmt->execute();
    $stmt->close();
}

function getCreateTableSql(mysqli $conn, string $table): string
{
    $safeTable = '`' . str_replace('`', '``', $table) . '`';
    $res = $conn->query("SHOW CREATE TABLE {$safeTable}");
    if ($res && $row = $res->fetch_assoc()) {
        $sql = $row['Create Table'] ?? '';
        if ($sql !== '') {
            return $sql . ";\n\n";
        }
    }
    return '';
}

function sqlValue(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $conn->real_escape_string((string)$value) . "'";
}

function generateInsertSql(mysqli $conn, string $table): string
{
    $sqlOutput = '';
    $safeTable = '`' . str_replace('`', '``', $table) . '`';
    $res = $conn->query("SELECT * FROM {$safeTable}");

    if (!$res || $res->num_rows === 0) {
        return $sqlOutput;
    }

    while ($row = $res->fetch_assoc()) {
        $columns = [];
        $values = [];

        foreach ($row as $column => $value) {
            $columns[] = '`' . str_replace('`', '``', $column) . '`';
            $values[] = sqlValue($conn, $value);
        }

        $sqlOutput .= "INSERT INTO {$safeTable} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
    }

    $sqlOutput .= "\n";
    return $sqlOutput;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!$items) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

$pageTitle = 'Backup';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : null;

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
$currentUser = $res ? $res->fetch_assoc() : null;
$stmt->close();

$currentRoleName = strtolower(trim((string)($currentUser['role_name'] ?? '')));
if (!in_array($currentRoleName, ['super admin', 'admin'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   BACKUP DIRECTORY
------------------------------------------------------- */
$backupDir = __DIR__ . '/uploads/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}

$success = '';
$error = '';

/* -------------------------------------------------------
   HANDLE DB BACKUP
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_database'])) {
    $databaseName = '';
    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    if ($dbRes && $dbRow = $dbRes->fetch_assoc()) {
        $databaseName = (string)($dbRow['db_name'] ?? 'database');
    }
    if ($databaseName === '') {
        $databaseName = 'database';
    }

    $timestamp = date('Ymd_His');
    $fileName = 'db_backup_' . $databaseName . '_' . $timestamp . '.sql';
    $filePath = $backupDir . '/' . $fileName;

    $tables = [];
    $res = $conn->query("SHOW TABLES");
    while ($res && $row = $res->fetch_array()) {
        if (!empty($row[0])) {
            $tables[] = $row[0];
        }
    }

    if (empty($tables)) {
        $error = 'No tables found for backup.';
    } else {
        $output = '';
        $output .= "-- Database Backup\n";
        $output .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Database: " . $databaseName . "\n\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "START TRANSACTION;\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        $output .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $output .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $output .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $output .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

        foreach ($tables as $table) {
            $output .= "-- --------------------------------------------------------\n";
            $output .= "-- Table structure for table `" . $table . "`\n";
            $output .= "-- --------------------------------------------------------\n\n";
            $output .= "DROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n";
            $output .= getCreateTableSql($conn, $table);
            $output .= "-- Dumping data for table `" . $table . "`\n\n";
            $output .= generateInsertSql($conn, $table);
        }

        $output .= "COMMIT;\n\n";
        $output .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        $output .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
        $output .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";

        if (file_put_contents($filePath, $output) !== false) {
            addAuditLogSafe(
                $conn,
                $businessId,
                $userId,
                'Backup',
                'Create',
                0,
                'Database backup created: ' . $fileName
            );
            $success = 'Database backup created successfully.';
        } else {
            $error = 'Failed to write backup file.';
        }
    }
}

/* -------------------------------------------------------
   HANDLE FILE BACKUP
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_files'])) {
    $timestamp = date('Ymd_His');
    $zipFileName = 'files_backup_' . $timestamp . '.zip';
    $zipFilePath = $backupDir . '/' . $zipFileName;

    $sourceDirs = [
        __DIR__ . '/uploads',
        __DIR__ . '/includes'
    ];

    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $error = 'Unable to create ZIP file.';
    } else {
        foreach ($sourceDirs as $sourceDir) {
            if (!is_dir($sourceDir)) {
                continue;
            }

            $rootName = basename($sourceDir);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($files as $file) {
                $filePath = $file->getPathname();

                if (strpos($filePath, $backupDir) === 0) {
                    continue;
                }

                $relativePath = $rootName . '/' . substr($filePath, strlen($sourceDir) + 1);

                if ($file->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } elseif ($file->isFile()) {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }

        $zip->close();

        addAuditLogSafe(
            $conn,
            $businessId,
            $userId,
            'Backup',
            'Create',
            0,
            'Files backup created: ' . $zipFileName
        );
        $success = 'Files backup created successfully.';
    }
}

/* -------------------------------------------------------
   HANDLE FULL BACKUP
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_full'])) {
    $timestamp = date('Ymd_His');
    $tempDir = $backupDir . '/temp_full_' . uniqid();
    @mkdir($tempDir, 0777, true);

    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    $databaseName = 'database';
    if ($dbRes && $dbRow = $dbRes->fetch_assoc()) {
        $databaseName = (string)($dbRow['db_name'] ?? 'database');
    }

    $sqlFile = $tempDir . '/database_backup.sql';
    $zipFileName = 'full_backup_' . $timestamp . '.zip';
    $zipFilePath = $backupDir . '/' . $zipFileName;

    $tables = [];
    $res = $conn->query("SHOW TABLES");
    while ($res && $row = $res->fetch_array()) {
        if (!empty($row[0])) {
            $tables[] = $row[0];
        }
    }

    $output = '';
    $output .= "-- Full Database Backup\n";
    $output .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
    $output .= "-- Database: " . $databaseName . "\n\n";
    $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $output .= "START TRANSACTION;\n";
    $output .= "SET time_zone = \"+00:00\";\n\n";
    $output .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

    foreach ($tables as $table) {
        $output .= "DROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n";
        $output .= getCreateTableSql($conn, $table);
        $output .= generateInsertSql($conn, $table);
    }

    $output .= "COMMIT;\n";
    file_put_contents($sqlFile, $output);

    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $error = 'Unable to create full backup ZIP.';
    } else {
        $zip->addFile($sqlFile, 'database_backup.sql');

        $sourceDirs = [
            __DIR__ . '/uploads',
            __DIR__ . '/includes'
        ];

        foreach ($sourceDirs as $sourceDir) {
            if (!is_dir($sourceDir)) {
                continue;
            }

            $rootName = basename($sourceDir);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($files as $file) {
                $filePath = $file->getPathname();

                if (strpos($filePath, $backupDir) === 0) {
                    continue;
                }

                $relativePath = $rootName . '/' . substr($filePath, strlen($sourceDir) + 1);

                if ($file->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } elseif ($file->isFile()) {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }

        $zip->close();
        rrmdir($tempDir);

        addAuditLogSafe(
            $conn,
            $businessId,
            $userId,
            'Backup',
            'Create',
            0,
            'Full backup created: ' . $zipFileName
        );
        $success = 'Full backup created successfully.';
    }
}

/* -------------------------------------------------------
   HANDLE DOWNLOAD
------------------------------------------------------- */
if (isset($_GET['download']) && $_GET['download'] !== '') {
    $file = basename((string)$_GET['download']);
    $filePath = $backupDir . '/' . $file;

    if (is_file($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $error = 'File not found.';
    }
}

/* -------------------------------------------------------
   HANDLE DELETE
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $file = basename((string)$_GET['delete']);
    $filePath = $backupDir . '/' . $file;

    if (is_file($filePath)) {
        if (@unlink($filePath)) {
            addAuditLogSafe(
                $conn,
                $businessId,
                $userId,
                'Backup',
                'Delete',
                0,
                'Deleted backup file: ' . $file
            );
            header('Location: backup.php?msg=deleted');
            exit;
        } else {
            $error = 'Failed to delete backup file.';
        }
    } else {
        $error = 'Backup file not found.';
    }
}

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'deleted') {
    $success = 'Backup file deleted successfully.';
}

/* -------------------------------------------------------
   LIST BACKUPS
------------------------------------------------------- */
$backupFiles = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    if ($files) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $backupDir . '/' . $file;
            if (is_file($path)) {
                $backupFiles[] = [
                    'name' => $file,
                    'size' => filesize($path),
                    'modified' => filemtime($path),
                    'type' => pathinfo($file, PATHINFO_EXTENSION)
                ];
            }
        }
    }
}

usort($backupFiles, function ($a, $b) {
    return $b['modified'] <=> $a['modified'];
});

$totalBackups = count($backupFiles);
$totalBackupSize = 0;
foreach ($backupFiles as $bf) {
    $totalBackupSize += (int)$bf['size'];
}

function formatBytes($bytes): string
{
    $bytes = (float)$bytes;
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return number_format($bytes, 0) . ' B';
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

                <div class="row">
                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalBackups; ?></h3>
                                <p class="text-muted mb-0">Total Backup Files</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo h(formatBytes($totalBackupSize)); ?></h3>
                                <p class="text-muted mb-0">Total Backup Size</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info mt-2"><?php echo is_writable($backupDir) ? 'Writable' : 'Read Only'; ?></h3>
                                <p class="text-muted mb-0">Backup Folder Status</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Create Backup</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" class="mb-3">
                                    <button type="submit" name="backup_database" value="1" class="btn btn-primary w-100">
                                        Backup Database (.sql)
                                    </button>
                                </form>

                                <form method="post" class="mb-3">
                                    <button type="submit" name="backup_files" value="1" class="btn btn-success w-100">
                                        Backup Files (.zip)
                                    </button>
                                </form>

                                <form method="post">
                                    <button type="submit" name="backup_full" value="1" class="btn btn-dark w-100">
                                        Full Backup (.zip)
                                    </button>
                                </form>

                                <div class="alert alert-warning mt-3 mb-0">
                                    <strong>Note:</strong> Full backup includes database dump and selected folders.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Backup Files</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>File Name</th>
                                                <th>Type</th>
                                                <th>Size</th>
                                                <th>Modified</th>
                                                <th style="min-width: 180px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($backupFiles)): ?>
                                                <?php foreach ($backupFiles as $index => $file): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <strong><?php echo h($file['name']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-secondary text-uppercase">
                                                                <?php echo h($file['type']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo h(formatBytes((int)$file['size'])); ?></td>
                                                        <td><?php echo date('d-m-Y h:i A', (int)$file['modified']); ?></td>
                                                        <td>
                                                            <a href="backup.php?download=<?php echo urlencode($file['name']); ?>" class="btn btn-sm btn-primary mb-1">
                                                                Download
                                                            </a>
                                                            <a href="backup.php?delete=<?php echo urlencode($file['name']); ?>"
                                                               class="btn btn-sm btn-danger mb-1"
                                                               onclick="return confirm('Are you sure you want to delete this backup file?');">
                                                                Delete
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">
                                                        No backup files found.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="alert alert-info mt-3 mb-0">
                                    Backup path: <strong><?php echo h($backupDir); ?></strong>
                                </div>
                            </div>
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

</body>
</html>