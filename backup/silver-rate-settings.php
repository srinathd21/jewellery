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

$pageTitle = 'Silver Rate Settings';

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
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLE
------------------------------------------------------- */
if (!tableExists($conn, 'silver_rate_history')) {
    die('silver_rate_history table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   DEFAULTS
------------------------------------------------------- */
$success = '';
$error = '';

$rateDate = date('Y-m-d');
$purity = '925';
$ratePerGram = '';
$remarks = '';

/* -------------------------------------------------------
   DELETE RATE
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    $stmt = $conn->prepare("
        DELETE FROM silver_rate_history
        WHERE id = ? AND business_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $deleteId, $businessId);

    if ($stmt->execute()) {
        if (function_exists('addAuditLog')) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Silver Rate Settings',
                'Delete',
                $deleteId,
                'Deleted silver rate entry'
            );
        }
        header('Location: silver-rate-settings.php?msg=deleted');
        exit;
    } else {
        $error = 'Failed to delete silver rate.';
    }
    $stmt->close();
}

/* -------------------------------------------------------
   EDIT LOAD
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $conn->prepare("
        SELECT id, rate_date, purity, rate_per_gram, remarks
        FROM silver_rate_history
        WHERE id = ? AND business_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $editId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $editRow = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($editRow) {
        $rateDate = (string)($editRow['rate_date'] ?? date('Y-m-d'));
        $purity = (string)($editRow['purity'] ?? '925');
        $ratePerGram = (string)($editRow['rate_per_gram'] ?? '');
        $remarks = (string)($editRow['remarks'] ?? '');
    }
}

/* -------------------------------------------------------
   SAVE RATE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = (int)($_POST['edit_id'] ?? 0);
    $rateDate = trim((string)($_POST['rate_date'] ?? date('Y-m-d')));
    $purity = trim((string)($_POST['purity'] ?? '925'));
    $ratePerGram = trim((string)($_POST['rate_per_gram'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($rateDate === '') {
        $error = 'Rate date is required.';
    } elseif ($purity === '') {
        $error = 'Purity is required.';
    } elseif ($ratePerGram === '' || !is_numeric($ratePerGram) || (float)$ratePerGram <= 0) {
        $error = 'Enter valid rate per gram.';
    } else {
        $ratePerGramFloat = (float)$ratePerGram;

        if ($editId > 0) {
            $stmt = $conn->prepare("
                UPDATE silver_rate_history
                SET rate_date = ?, purity = ?, rate_per_gram = ?, remarks = ?
                WHERE id = ? AND business_id = ?
                LIMIT 1
            ");
            $stmt->bind_param(
                'ssdsii',
                $rateDate,
                $purity,
                $ratePerGramFloat,
                $remarks,
                $editId,
                $businessId
            );

            if ($stmt->execute()) {
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Silver Rate Settings',
                        'Update',
                        $editId,
                        'Updated silver rate entry'
                    );
                }
                header('Location: silver-rate-settings.php?msg=updated');
                exit;
            } else {
                $error = 'Failed to update rate.';
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                SELECT id
                FROM silver_rate_history
                WHERE business_id = ? AND rate_date = ? AND purity = ?
                LIMIT 1
            ");
            $stmt->bind_param('iss', $businessId, $rateDate, $purity);
            $stmt->execute();
            $res = $stmt->get_result();
            $existsRow = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($existsRow) {
                $existingId = (int)$existsRow['id'];

                $stmt = $conn->prepare("
                    UPDATE silver_rate_history
                    SET rate_per_gram = ?, remarks = ?
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                $stmt->bind_param('dsii', $ratePerGramFloat, $remarks, $existingId, $businessId);

                if ($stmt->execute()) {
                    if (function_exists('addAuditLog')) {
                        addAuditLog(
                            $conn,
                            $businessId,
                            $userId,
                            'Silver Rate Settings',
                            'Update',
                            $existingId,
                            'Updated existing daily silver rate'
                        );
                    }
                    header('Location: silver-rate-settings.php?msg=updated');
                    exit;
                } else {
                    $error = 'Failed to update existing rate.';
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO silver_rate_history (
                        business_id,
                        rate_date,
                        purity,
                        rate_per_gram,
                        remarks,
                        updated_by,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param(
                    'issdsi',
                    $businessId,
                    $rateDate,
                    $purity,
                    $ratePerGramFloat,
                    $remarks,
                    $userId
                );

                if ($stmt->execute()) {
                    $newId = (int)$stmt->insert_id;

                    if (function_exists('addAuditLog')) {
                        addAuditLog(
                            $conn,
                            $businessId,
                            $userId,
                            'Silver Rate Settings',
                            'Create',
                            $newId,
                            'Created silver rate entry'
                        );
                    }
                    header('Location: silver-rate-settings.php?msg=created');
                    exit;
                } else {
                    $error = 'Failed to save silver rate.';
                }
                $stmt->close();
            }
        }
    }
}

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Silver rate added successfully.';
} elseif ($msg === 'updated') {
    $success = 'Silver rate updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Silver rate deleted successfully.';
}

/* -------------------------------------------------------
   TODAY RATES
------------------------------------------------------- */
$todayRates = [];
$stmt = $conn->prepare("
    SELECT id, rate_date, purity, rate_per_gram, rate_per_kg, remarks, created_at
    FROM silver_rate_history
    WHERE business_id = ? AND rate_date = ?
    ORDER BY purity ASC, id DESC
");
$stmt->bind_param('is', $businessId, $rateDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $todayRates[] = $row;
}
$stmt->close();

/* -------------------------------------------------------
   RATE HISTORY
------------------------------------------------------- */
$historyRows = [];
$stmt = $conn->prepare("
    SELECT id, rate_date, purity, rate_per_gram, rate_per_kg, remarks, created_at
    FROM silver_rate_history
    WHERE business_id = ?
    ORDER BY rate_date DESC, id DESC
    LIMIT 50
");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $historyRows[] = $row;
}
$stmt->close();
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
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <?php echo $editId > 0 ? 'Edit Silver Rate' : 'Add Silver Rate'; ?>
                                </h4>

                                <form method="post">
                                    <input type="hidden" name="edit_id" value="<?php echo (int)$editId; ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Rate Date</label>
                                        <input type="date" name="rate_date" class="form-control" value="<?php echo h($rateDate); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Purity</label>
                                        <select name="purity" class="form-select" required>
                                            <option value="999" <?php echo $purity === '999' ? 'selected' : ''; ?>>999</option>
                                            <option value="925" <?php echo $purity === '925' ? 'selected' : ''; ?>>925</option>
                                            <option value="900" <?php echo $purity === '900' ? 'selected' : ''; ?>>900</option>
                                            <option value="800" <?php echo $purity === '800' ? 'selected' : ''; ?>>800</option>
                                            <option value="other" <?php echo $purity === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Rate Per Gram</label>
                                        <input type="text" name="rate_per_gram" class="form-control" value="<?php echo h($ratePerGram); ?>" placeholder="Enter rate per gram" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks" class="form-control" rows="4"><?php echo h($remarks); ?></textarea>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary waves-effect waves-light">
                                            <?php echo $editId > 0 ? 'Update Rate' : 'Save Rate'; ?>
                                        </button>

                                        <?php if ($editId > 0): ?>
                                            <a href="silver-rate-settings.php" class="btn btn-secondary waves-effect">
                                                Cancel Edit
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Today Rates</h4>

                                <?php if (!empty($todayRates)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Purity</th>
                                                    <th>Gram</th>
                                                    <th>KG</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($todayRates as $rate): ?>
                                                    <tr>
                                                        <td><?php echo h($rate['purity']); ?></td>
                                                        <td>₹<?php echo number_format((float)$rate['rate_per_gram'], 2); ?></td>
                                                        <td>₹<?php echo number_format((float)$rate['rate_per_kg'], 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light mb-0">No rate added for today.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Rate History</h4>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Date</th>
                                                <th>Purity</th>
                                                <th>Rate / Gram</th>
                                                <th>Rate / KG</th>
                                                <th>Remarks</th>
                                                <th>Created</th>
                                                <th style="min-width: 140px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($historyRows)): ?>
                                                <?php foreach ($historyRows as $index => $row): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo h(date('d-m-Y', strtotime($row['rate_date']))); ?></td>
                                                        <td><?php echo h($row['purity']); ?></td>
                                                        <td>₹<?php echo number_format((float)$row['rate_per_gram'], 2); ?></td>
                                                        <td>₹<?php echo number_format((float)$row['rate_per_kg'], 2); ?></td>
                                                        <td><?php echo h($row['remarks']); ?></td>
                                                        <td><?php echo !empty($row['created_at']) ? date('d-m-Y h:i A', strtotime($row['created_at'])) : '-'; ?></td>
                                                        <td>
                                                            <a href="silver-rate-settings.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary mb-1">
                                                                Edit
                                                            </a>
                                                            <a href="silver-rate-settings.php?delete=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-danger mb-1" onclick="return confirm('Are you sure you want to delete this rate?');">
                                                                Delete
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted">No silver rate history found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
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