<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

// Check if config loaded properly
if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

// Helper function for HTML escaping
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Page variables
$pageTitle = 'Silver Rate Management';
$currentPage = 'silver-rates';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
function isUserLoggedIn() {
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

if (!isUserLoggedIn()) {
    $current_file = basename($_SERVER['PHP_SELF']);
    if ($current_file != 'login.php') {
        header('Location: login.php');
        exit;
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

// If business_id is not set in session, try to get from database
if ($businessId <= 0 && isUserLoggedIn()) {
    $stmt = $conn->prepare("SELECT business_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $businessId = (int)$row['business_id'];
        $_SESSION['business_id'] = $businessId;
    }
    $stmt->close();
}

if ($businessId <= 0) {
    die('Business not configured. Please contact administrator.');
}

// Get user role for permission checks
$userRole = $_SESSION['role_name'] ?? '';
$isAdmin = in_array($userRole, ['Super Admin', 'Admin', 'Manager']);

// Ensure silver_rate_history table exists
function createSilverRateTable($conn, $businessId) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'silver_rate_history'");
    if ($tableCheck->num_rows == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS `silver_rate_history` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `business_id` int(10) UNSIGNED NOT NULL,
            `rate_date` date NOT NULL,
            `purity` varchar(20) NOT NULL DEFAULT '925',
            `rate_per_gram` decimal(12,2) NOT NULL,
            `rate_per_kg` decimal(12,2) GENERATED ALWAYS AS (rate_per_gram * 1000) STORED,
            `remarks` varchar(255) DEFAULT NULL,
            `updated_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_rate_date_purity_business` (`business_id`,`rate_date`,`purity`),
            KEY `fk_silver_rate_user` (`updated_by`),
            KEY `idx_rate_date` (`rate_date`),
            KEY `fk_silver_rate_business` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->query($sql);
        
        // Insert some default rates
        $defaultRates = [
            [date('Y-m-d'), '999', 78.50, '24K Pure Silver'],
            [date('Y-m-d'), '925', 72.50, 'Sterling Silver'],
            [date('Y-m-d'), '916', 71.80, '22K Silver'],
            [date('Y-m-d'), '835', 65.00, 'Coin Silver'],
        ];
        
        $insertSql = "INSERT INTO silver_rate_history (business_id, rate_date, purity, rate_per_gram, remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertSql);
        
        foreach ($defaultRates as $rate) {
            $stmt->bind_param('issdsi', $businessId, $rate[0], $rate[1], $rate[2], $rate[3], $userId);
            $stmt->execute();
        }
        $stmt->close();
    }
}

createSilverRateTable($conn, $businessId);

// Process form submission for adding/updating rate
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $rate_date = $_POST['rate_date'] ?? date('Y-m-d');
    $purity = $_POST['purity'] ?? '925';
    $rate_per_gram = (float)($_POST['rate_per_gram'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($action === 'add') {
        // Check if rate already exists for this date and purity
        $checkStmt = $conn->prepare("SELECT id FROM silver_rate_history WHERE business_id = ? AND rate_date = ? AND purity = ?");
        $checkStmt->bind_param('iss', $businessId, $rate_date, $purity);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing rate
            $updateStmt = $conn->prepare("UPDATE silver_rate_history SET rate_per_gram = ?, remarks = ?, updated_by = ? WHERE business_id = ? AND rate_date = ? AND purity = ?");
            $updateStmt->bind_param('dsiiss', $rate_per_gram, $remarks, $userId, $businessId, $rate_date, $purity);
            
            if ($updateStmt->execute()) {
                $success = "Silver rate updated successfully for " . date('d-m-Y', strtotime($rate_date)) . " (" . $purity . " purity)!";
            } else {
                $error = "Failed to update silver rate: " . $updateStmt->error;
            }
            $updateStmt->close();
        } else {
            // Insert new rate
            $insertStmt = $conn->prepare("INSERT INTO silver_rate_history (business_id, rate_date, purity, rate_per_gram, remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
            $insertStmt->bind_param('issdsi', $businessId, $rate_date, $purity, $rate_per_gram, $remarks, $userId);
            
            if ($insertStmt->execute()) {
                $success = "Silver rate added successfully for " . date('d-m-Y', strtotime($rate_date)) . " (" . $purity . " purity)!";
            } else {
                $error = "Failed to add silver rate: " . $insertStmt->error;
            }
            $insertStmt->close();
        }
        $checkStmt->close();
    }
    
    if ($action === 'delete' && isset($_POST['rate_id'])) {
        $rateId = (int)$_POST['rate_id'];
        $deleteStmt = $conn->prepare("DELETE FROM silver_rate_history WHERE id = ? AND business_id = ?");
        $deleteStmt->bind_param('ii', $rateId, $businessId);
        
        if ($deleteStmt->execute()) {
            $success = "Silver rate deleted successfully!";
        } else {
            $error = "Failed to delete silver rate: " . $deleteStmt->error;
        }
        $deleteStmt->close();
    }
}

// Get filter parameters
$searchDate = $_GET['date'] ?? date('Y-m-d');
$searchPurity = $_GET['purity'] ?? '';
$searchMonth = $_GET['month'] ?? '';

// Build query for rates listing
$query = "SELECT * FROM silver_rate_history WHERE business_id = ?";
$params = [$businessId];
$types = "i";

if (!empty($searchDate)) {
    $query .= " AND rate_date = ?";
    $params[] = $searchDate;
    $types .= "s";
}

if (!empty($searchPurity)) {
    $query .= " AND purity = ?";
    $params[] = $searchPurity;
    $types .= "s";
}

if (!empty($searchMonth)) {
    $query .= " AND DATE_FORMAT(rate_date, '%Y-%m') = ?";
    $params[] = $searchMonth;
    $types .= "s";
}

$query .= " ORDER BY rate_date DESC, FIELD(purity, '999', '925', '916', '835', '800')";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get today's rates for display
$today = date('Y-m-d');
$todayRates = [];
$todayStmt = $conn->prepare("SELECT purity, rate_per_gram FROM silver_rate_history WHERE business_id = ? AND rate_date = ? ORDER BY FIELD(purity, '999', '925', '916', '835', '800')");
$todayStmt->bind_param('is', $businessId, $today);
$todayStmt->execute();
$todayRatesResult = $todayStmt->get_result();
while ($row = $todayRatesResult->fetch_assoc()) {
    $todayRates[$row['purity']] = $row['rate_per_gram'];
}
$todayStmt->close();

// Get available purities for dropdown
$puritiesStmt = $conn->prepare("SELECT DISTINCT purity FROM silver_rate_history WHERE business_id = ? ORDER BY purity DESC");
$puritiesStmt->bind_param('i', $businessId);
$puritiesStmt->execute();
$availablePurities = $puritiesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$puritiesStmt->close();

// Get available months for filter
$monthsStmt = $conn->prepare("SELECT DISTINCT DATE_FORMAT(rate_date, '%Y-%m') as month FROM silver_rate_history WHERE business_id = ? ORDER BY month DESC");
$monthsStmt->bind_param('i', $businessId);
$monthsStmt->execute();
$availableMonths = $monthsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$monthsStmt->close();

include('includes/head.php');
?>

<style>
    .rate-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        padding: 20px;
        color: white;
        margin-bottom: 20px;
        transition: transform 0.3s;
    }
    .rate-card:hover {
        transform: translateY(-5px);
    }
    .rate-card h6 {
        font-size: 14px;
        opacity: 0.9;
        margin-bottom: 10px;
    }
    .rate-card h3 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 5px;
    }
    .rate-card p {
        font-size: 12px;
        opacity: 0.8;
        margin-bottom: 0;
    }
    .purity-999 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .purity-925 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .purity-916 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: #333; }
    .purity-835 { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
    .purity-800 { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; }
    .table-rate-card {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 15px;
        margin-bottom: 20px;
    }
    .trend-up { color: #00b894; }
    .trend-down { color: #d63031; }
    .trend-steady { color: #fdcb6e; }
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

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0">Silver Rate Management</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Silver Rates</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Today's Silver Rates Cards -->
                <div class="row">
                    <div class="col-12">
                        <h5 class="mb-3">Today's Silver Rates (<?php echo date('d-m-Y'); ?>)</h5>
                    </div>
                    <div class="col-md-3">
                        <div class="rate-card purity-999">
                            <h6>Pure Silver (999)</h6>
                            <h3>₹<?php echo number_format($todayRates['999'] ?? 0, 2); ?></h3>
                            <p>per gram</p>
                            <small>24K Pure Silver</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="rate-card purity-925">
                            <h6>Sterling Silver (925)</h6>
                            <h3>₹<?php echo number_format($todayRates['925'] ?? 0, 2); ?></h3>
                            <p>per gram</p>
                            <small>92.5% Purity</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="rate-card purity-916">
                            <h6>22K Silver (916)</h6>
                            <h3>₹<?php echo number_format($todayRates['916'] ?? 0, 2); ?></h3>
                            <p>per gram</p>
                            <small>91.6% Purity</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="rate-card purity-835">
                            <h6>Coin Silver (835)</h6>
                            <h3>₹<?php echo number_format($todayRates['835'] ?? 0, 2); ?></h3>
                            <p>per gram</p>
                            <small>83.5% Purity</small>
                        </div>
                    </div>
                </div>

                <!-- Add/Update Rate Form -->
                <?php if ($isAdmin): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-plus-circle"></i> Add / Update Silver Rate
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" class="row g-3">
                            <input type="hidden" name="action" value="add">
                            <div class="col-md-3">
                                <label class="form-label">Rate Date</label>
                                <input type="date" name="rate_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Purity</label>
                                <select name="purity" class="form-select" required>
                                    <option value="999">999 - Pure Silver (24K)</option>
                                    <option value="925">925 - Sterling Silver</option>
                                    <option value="916">916 - 22K Silver</option>
                                    <option value="835">835 - Coin Silver</option>
                                    <option value="800">800 - 80% Silver</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Rate per Gram (₹)</label>
                                <input type="number" step="0.01" name="rate_per_gram" class="form-control" placeholder="Enter rate" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Optional remarks">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Save Rate
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Filter by Date</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $searchDate; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Filter by Purity</label>
                                <select name="purity" class="form-select">
                                    <option value="">All Purities</option>
                                    <?php foreach ($availablePurities as $p): ?>
                                        <option value="<?php echo $p['purity']; ?>" <?php echo ($searchPurity == $p['purity']) ? 'selected' : ''; ?>>
                                            <?php echo $p['purity']; ?> - 
                                            <?php 
                                                switch($p['purity']) {
                                                    case '999': echo 'Pure Silver'; break;
                                                    case '925': echo 'Sterling Silver'; break;
                                                    case '916': echo '22K Silver'; break;
                                                    case '835': echo 'Coin Silver'; break;
                                                    default: echo 'Silver';
                                                }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Filter by Month</label>
                                <select name="month" class="form-select">
                                    <option value="">All Months</option>
                                    <?php foreach ($availableMonths as $m): ?>
                                        <option value="<?php echo $m['month']; ?>" <?php echo ($searchMonth == $m['month']) ? 'selected' : ''; ?>>
                                            <?php echo date('F Y', strtotime($m['month'] . '-01')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div class="d-grid gap-2 d-md-flex">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                    <a href="silver-rates.php" class="btn btn-secondary">
                                        <i class="fas fa-sync-alt"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Rates History Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history"></i> Silver Rate History
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered dt-responsive nowrap" id="ratesTable" width="100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Date</th>
                                        <th>Purity</th>
                                        <th>Rate per Gram (₹)</th>
                                        <th>Rate per KG (₹)</th>
                                        <th>Remarks</th>
                                        <th>Updated By</th>
                                        <th>Updated At</th>
                                        <?php if ($isAdmin): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($rates) > 0): ?>
                                        <?php foreach ($rates as $rate): ?>
                                            <?php
                                            $purityLabel = '';
                                            switch($rate['purity']) {
                                                case '999': $purityLabel = 'Pure Silver (24K)'; break;
                                                case '925': $purityLabel = 'Sterling Silver'; break;
                                                case '916': $purityLabel = '22K Silver'; break;
                                                case '835': $purityLabel = 'Coin Silver'; break;
                                                default: $purityLabel = $rate['purity'] . ' Silver';
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo $rate['id']; ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($rate['rate_date'])); ?></td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo $rate['purity']; ?></span>
                                                    <small class="text-muted d-block"><?php echo $purityLabel; ?></small>
                                                </td>
                                                <td class="text-end">
                                                    <strong>₹<?php echo number_format($rate['rate_per_gram'], 2); ?></strong>
                                                </td>
                                                <td class="text-end">₹<?php echo number_format($rate['rate_per_gram'] * 1000, 2); ?></td>
                                                <td><?php echo h($rate['remarks'] ?? '-'); ?></td>
                                                <td>
                                                    <?php
                                                    if ($rate['updated_by']) {
                                                        $userStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                                                        $userStmt->bind_param('i', $rate['updated_by']);
                                                        $userStmt->execute();
                                                        $userResult = $userStmt->get_result();
                                                        if ($user = $userResult->fetch_assoc()) {
                                                            echo h($user['full_name']);
                                                        } else {
                                                            echo 'System';
                                                        }
                                                        $userStmt->close();
                                                    } else {
                                                        echo 'System';
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo date('d-m-Y H:i:s', strtotime($rate['created_at'])); ?></td>
                                                <?php if ($isAdmin): ?>
                                                <td class="text-center">
                                                    <form method="POST" action="" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this rate?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="rate_id" value="<?php echo $rate['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo $isAdmin ? 9 : 8; ?>" class="text-center text-muted">
                                                No silver rates found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Rate Calculation Tool -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calculator"></i> Silver Rate Calculator
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Weight (grams)</label>
                                <input type="number" step="0.001" id="calc_weight" class="form-control" placeholder="Enter weight in grams">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Purity</label>
                                <select id="calc_purity" class="form-select">
                                    <option value="999">999 - Pure Silver (24K)</option>
                                    <option value="925" selected>925 - Sterling Silver</option>
                                    <option value="916">916 - 22K Silver</option>
                                    <option value="835">835 - Coin Silver</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Rate per Gram (₹)</label>
                                <input type="number" step="0.01" id="calc_rate" class="form-control" placeholder="Enter rate or use today's rate">
                                <small class="text-muted">Leave empty to use today's rate</small>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    <strong>Calculation Result:</strong><br>
                                    Silver Value: <strong id="calc_result">₹0.00</strong>
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

<script>
$(document).ready(function() {
    // Initialize DataTable
    if (typeof $.fn.DataTable !== 'undefined' && $('#ratesTable tbody tr').length > 1) {
        $('#ratesTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            responsive: true,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    }

    // Rate Calculator
    function calculateSilverValue() {
        var weight = parseFloat($('#calc_weight').val()) || 0;
        var purity = $('#calc_purity').val();
        var rate = parseFloat($('#calc_rate').val());
        
        // If rate not provided, use today's rate
        if (isNaN(rate) || rate === 0) {
            <?php foreach ($todayRates as $purity => $rate): ?>
            if (purity === '<?php echo $purity; ?>') {
                rate = <?php echo $rate; ?>;
            }
            <?php endforeach; ?>
        }
        
        var value = weight * rate;
        $('#calc_result').text('₹' + value.toFixed(2));
    }
    
    $('#calc_weight, #calc_purity, #calc_rate').on('keyup change', calculateSilverValue);
    
    // Pre-populate calculator with today's rate for selected purity
    $('#calc_purity').change(function() {
        var purity = $(this).val();
        <?php foreach ($todayRates as $purity => $rate): ?>
        if (purity === '<?php echo $purity; ?>') {
            $('#calc_rate').val(<?php echo $rate; ?>);
        }
        <?php endforeach; ?>
        calculateSilverValue();
    });
    
    // Trigger calculator on load
    $('#calc_purity').trigger('change');
});
</script>

</body>
</html>