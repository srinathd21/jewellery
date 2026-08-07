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
$pageTitle = 'Gold Rate Management';
$currentPage = 'gold-rates';

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

// Ensure gold_rate_history table exists
function createGoldRateTable($conn, $businessId) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'gold_rate_history'");
    if ($tableCheck->num_rows == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS `gold_rate_history` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `business_id` int(10) UNSIGNED NOT NULL,
            `rate_date` date NOT NULL,
            `carat` varchar(10) NOT NULL,
            `purity_percent` decimal(5,2) NOT NULL,
            `rate_per_gram` decimal(12,2) NOT NULL,
            `rate_per_sovereign` decimal(12,2) GENERATED ALWAYS AS (rate_per_gram * 8) STORED,
            `rate_per_10gram` decimal(12,2) GENERATED ALWAYS AS (rate_per_gram * 10) STORED,
            `remarks` varchar(255) DEFAULT NULL,
            `updated_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_rate_date_carat_business` (`business_id`,`rate_date`,`carat`),
            KEY `fk_gold_rate_user` (`updated_by`),
            KEY `idx_rate_date` (`rate_date`),
            KEY `fk_gold_rate_business` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->query($sql);
        
        // Insert some default rates
        $defaultRates = [
            [date('Y-m-d'), '24K', 99.90, 4850.00, '24 Carat Pure Gold'],
            [date('Y-m-d'), '22K', 91.67, 4450.00, '22 Carat Gold'],
            [date('Y-m-d'), '18K', 75.00, 3650.00, '18 Carat Gold'],
            [date('Y-m-d'), '14K', 58.33, 2850.00, '14 Carat Gold'],
        ];
        
        $insertSql = "INSERT INTO gold_rate_history (business_id, rate_date, carat, purity_percent, rate_per_gram, remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertSql);
        
        foreach ($defaultRates as $rate) {
            $stmt->bind_param('issddsi', $businessId, $rate[0], $rate[1], $rate[2], $rate[3], $rate[4], $userId);
            $stmt->execute();
        }
        $stmt->close();
    }
}

createGoldRateTable($conn, $businessId);

// Process form submission for adding/updating rate
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $rate_date = $_POST['rate_date'] ?? date('Y-m-d');
    $carat = $_POST['carat'] ?? '22K';
    $purity_percent = (float)($_POST['purity_percent'] ?? 0);
    $rate_per_gram = (float)($_POST['rate_per_gram'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($action === 'add') {
        // Check if rate already exists for this date and carat
        $checkStmt = $conn->prepare("SELECT id FROM gold_rate_history WHERE business_id = ? AND rate_date = ? AND carat = ?");
        $checkStmt->bind_param('iss', $businessId, $rate_date, $carat);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing rate
            $updateStmt = $conn->prepare("UPDATE gold_rate_history SET purity_percent = ?, rate_per_gram = ?, remarks = ?, updated_by = ? WHERE business_id = ? AND rate_date = ? AND carat = ?");
            $updateStmt->bind_param('ddsiiss', $purity_percent, $rate_per_gram, $remarks, $userId, $businessId, $rate_date, $carat);
            
            if ($updateStmt->execute()) {
                $success = "Gold rate updated successfully for " . date('d-m-Y', strtotime($rate_date)) . " (" . $carat . ")!";
            } else {
                $error = "Failed to update gold rate: " . $updateStmt->error;
            }
            $updateStmt->close();
        } else {
            // Insert new rate
            $insertStmt = $conn->prepare("INSERT INTO gold_rate_history (business_id, rate_date, carat, purity_percent, rate_per_gram, remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->bind_param('issddsi', $businessId, $rate_date, $carat, $purity_percent, $rate_per_gram, $remarks, $userId);
            
            if ($insertStmt->execute()) {
                $success = "Gold rate added successfully for " . date('d-m-Y', strtotime($rate_date)) . " (" . $carat . ")!";
            } else {
                $error = "Failed to add gold rate: " . $insertStmt->error;
            }
            $insertStmt->close();
        }
        $checkStmt->close();
    }
    
    if ($action === 'delete' && isset($_POST['rate_id'])) {
        $rateId = (int)$_POST['rate_id'];
        $deleteStmt = $conn->prepare("DELETE FROM gold_rate_history WHERE id = ? AND business_id = ?");
        $deleteStmt->bind_param('ii', $rateId, $businessId);
        
        if ($deleteStmt->execute()) {
            $success = "Gold rate deleted successfully!";
        } else {
            $error = "Failed to delete gold rate: " . $deleteStmt->error;
        }
        $deleteStmt->close();
    }
}

// Get filter parameters
$searchDate = $_GET['date'] ?? date('Y-m-d');
$searchCarat = $_GET['carat'] ?? '';
$searchMonth = $_GET['month'] ?? '';

// Build query for rates listing
$query = "SELECT * FROM gold_rate_history WHERE business_id = ?";
$params = [$businessId];
$types = "i";

if (!empty($searchDate)) {
    $query .= " AND rate_date = ?";
    $params[] = $searchDate;
    $types .= "s";
}

if (!empty($searchCarat)) {
    $query .= " AND carat = ?";
    $params[] = $searchCarat;
    $types .= "s";
}

if (!empty($searchMonth)) {
    $query .= " AND DATE_FORMAT(rate_date, '%Y-%m') = ?";
    $params[] = $searchMonth;
    $types .= "s";
}

$query .= " ORDER BY rate_date DESC, FIELD(carat, '24K', '22K', '18K', '14K', '10K')";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get today's rates for display
$today = date('Y-m-d');
$todayRates = [];
$todayStmt = $conn->prepare("SELECT carat, purity_percent, rate_per_gram FROM gold_rate_history WHERE business_id = ? AND rate_date = ? ORDER BY FIELD(carat, '24K', '22K', '18K', '14K', '10K')");
$todayStmt->bind_param('is', $businessId, $today);
$todayStmt->execute();
$todayRatesResult = $todayStmt->get_result();
while ($row = $todayRatesResult->fetch_assoc()) {
    $todayRates[$row['carat']] = [
        'purity' => $row['purity_percent'],
        'rate' => $row['rate_per_gram']
    ];
}
$todayStmt->close();

// Get available carats for dropdown
$caratsStmt = $conn->prepare("SELECT DISTINCT carat, purity_percent FROM gold_rate_history WHERE business_id = ? ORDER BY FIELD(carat, '24K', '22K', '18K', '14K', '10K')");
$caratsStmt->bind_param('i', $businessId);
$caratsStmt->execute();
$availableCarats = $caratsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$caratsStmt->close();

// Get available months for filter
$monthsStmt = $conn->prepare("SELECT DISTINCT DATE_FORMAT(rate_date, '%Y-%m') as month FROM gold_rate_history WHERE business_id = ? ORDER BY month DESC");
$monthsStmt->bind_param('i', $businessId);
$monthsStmt->execute();
$availableMonths = $monthsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$monthsStmt->close();

include('includes/head.php');
?>

<style>
    .rate-card {
        background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
        border-radius: 15px;
        padding: 20px;
        color: white;
        margin-bottom: 20px;
        transition: transform 0.3s, box-shadow 0.3s;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    .rate-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }
    .rate-card:before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: rgba(255,255,255,0.1);
        transform: rotate(45deg);
        pointer-events: none;
    }
    .rate-card h6 {
        font-size: 13px;
        opacity: 0.95;
        margin-bottom: 8px;
        letter-spacing: 1px;
    }
    .rate-card h3 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 5px;
    }
    .rate-card .purity-badge {
        display: inline-block;
        background: rgba(255,255,255,0.2);
        padding: 3px 8px;
        border-radius: 15px;
        font-size: 10px;
        margin-top: 8px;
    }
    .rate-card small {
        font-size: 11px;
        opacity: 0.8;
    }
    .carat-24k { background: linear-gradient(135deg, #f7971e 0%, #ffd200 100%); }
    .carat-22k { background: linear-gradient(135deg, #e65c00 0%, #F9D423 100%); }
    .carat-18k { background: linear-gradient(135deg, #cb2d3e 0%, #ef473a 100%); }
    .carat-14k { background: linear-gradient(135deg, #b224ef 0%, #7579ff 100%); }
    .carat-10k { background: linear-gradient(135deg, #4ca1af 0%, #2c3e50 100%); }
    
    .calculator-box {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 15px;
        padding: 25px;
        color: white;
    }
    .calculator-box input, .calculator-box select {
        background: rgba(255,255,255,0.9);
        border: none;
        border-radius: 8px;
        padding: 10px;
    }
    .result-box {
        background: rgba(255,255,255,0.2);
        border-radius: 10px;
        padding: 15px;
        text-align: center;
    }
    .result-box h2 {
        font-size: 32px;
        font-weight: bold;
        margin: 0;
        color: #ffd700;
    }
    .trend-up { color: #00b894; }
    .trend-down { color: #d63031; }
    .info-text {
        font-size: 12px;
        color: #6c757d;
        margin-top: 5px;
    }
    .sovereign-card {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 15px;
        text-align: center;
        margin-top: 15px;
    }
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
                            <h4 class="mb-sm-0">Gold Rate Management</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Gold Rates</li>
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

                <!-- Today's Gold Rates Cards -->
                <div class="row">
                    <div class="col-12">
                        <h5 class="mb-3">Today's Gold Rates (<?php echo date('d-m-Y'); ?>)</h5>
                    </div>
                    
                    <?php if (isset($todayRates['24K'])): ?>
                    <div class="col-md-3">
                        <div class="rate-card carat-24k">
                            <h6>24 Carat Gold</h6>
                            <h3>₹<?php echo number_format($todayRates['24K']['rate'], 2); ?></h3>
                            <p>per gram</p>
                            <div class="purity-badge">Purity: <?php echo $todayRates['24K']['purity']; ?>%</div>
                            <small>Pure Gold (999.9)</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($todayRates['22K'])): ?>
                    <div class="col-md-3">
                        <div class="rate-card carat-22k">
                            <h6>22 Carat Gold</h6>
                            <h3>₹<?php echo number_format($todayRates['22K']['rate'], 2); ?></h3>
                            <p>per gram</p>
                            <div class="purity-badge">Purity: <?php echo $todayRates['22K']['purity']; ?>%</div>
                            <small>916 Hallmark</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($todayRates['18K'])): ?>
                    <div class="col-md-3">
                        <div class="rate-card carat-18k">
                            <h6>18 Carat Gold</h6>
                            <h3>₹<?php echo number_format($todayRates['18K']['rate'], 2); ?></h3>
                            <p>per gram</p>
                            <div class="purity-badge">Purity: <?php echo $todayRates['18K']['purity']; ?>%</div>
                            <small>750 Hallmark</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($todayRates['14K'])): ?>
                    <div class="col-md-3">
                        <div class="rate-card carat-14k">
                            <h6>14 Carat Gold</h6>
                            <h3>₹<?php echo number_format($todayRates['14K']['rate'], 2); ?></h3>
                            <p>per gram</p>
                            <div class="purity-badge">Purity: <?php echo $todayRates['14K']['purity']; ?>%</div>
                            <small>585 Hallmark</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sovereign Rate Cards -->
                <div class="row mt-2">
                    <div class="col-12">
                        <h5 class="mb-3">Sovereign Rates (8 grams)</h5>
                    </div>
                    <div class="col-md-12">
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($todayRates as $carat => $data): ?>
                            <div class="sovereign-card flex-grow-1">
                                <strong><?php echo $carat; ?></strong><br>
                                <span class="text-primary">₹<?php echo number_format($data['rate'] * 8, 2); ?></span>
                                <small class="text-muted d-block">per sovereign</small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Add/Update Rate Form -->
                <?php if ($isAdmin): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-plus-circle"></i> Add / Update Gold Rate
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" class="row g-3">
                            <input type="hidden" name="action" value="add">
                            <div class="col-md-2">
                                <label class="form-label">Rate Date</label>
                                <input type="date" name="rate_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Carat</label>
                                <select name="carat" id="carat_select" class="form-select" required>
                                    <option value="24K">24 Carat (Pure Gold)</option>
                                    <option value="22K" selected>22 Carat (916)</option>
                                    <option value="18K">18 Carat (750)</option>
                                    <option value="14K">14 Carat (585)</option>
                                    <option value="10K">10 Carat (417)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Purity (%)</label>
                                <input type="number" step="0.01" name="purity_percent" id="purity_percent" class="form-control" value="91.67" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Rate per Gram (₹)</label>
                                <input type="number" step="0.01" name="rate_per_gram" id="rate_per_gram" class="form-control" placeholder="Enter rate" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Rate per Sovereign (₹)</label>
                                <input type="text" id="rate_per_sovereign" class="form-control" readonly style="background:#e9ecef;">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Save Rate
                                </button>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Optional remarks">
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
                                <label class="form-label">Filter by Carat</label>
                                <select name="carat" class="form-select">
                                    <option value="">All Carats</option>
                                    <?php foreach ($availableCarats as $c): ?>
                                        <option value="<?php echo $c['carat']; ?>" <?php echo ($searchCarat == $c['carat']) ? 'selected' : ''; ?>>
                                            <?php echo $c['carat']; ?> (<?php echo $c['purity_percent']; ?>% purity)
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
                                    <a href="gold-rates.php" class="btn btn-secondary">
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
                            <i class="fas fa-history"></i> Gold Rate History
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered dt-responsive nowrap" id="ratesTable" width="100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Date</th>
                                        <th>Carat</th>
                                        <th>Purity (%)</th>
                                        <th>Rate/g (₹)</th>
                                        <th>Rate/Sovereign (₹)</th>
                                        <th>Rate/10g (₹)</th>
                                        <th>Remarks</th>
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
                                            $caratClass = '';
                                            switch($rate['carat']) {
                                                case '24K': $caratClass = 'badge bg-warning'; break;
                                                case '22K': $caratClass = 'badge bg-info'; break;
                                                case '18K': $caratClass = 'badge bg-danger'; break;
                                                case '14K': $caratClass = 'badge bg-purple'; break;
                                                default: $caratClass = 'badge bg-secondary';
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo $rate['id']; ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($rate['rate_date'])); ?></td>
                                                <td>
                                                    <span class="<?php echo $caratClass; ?>"><?php echo $rate['carat']; ?></span>
                                                </td>
                                                <td class="text-end"><?php echo number_format($rate['purity_percent'], 2); ?>%</td>
                                                <td class="text-end">
                                                    <strong>₹<?php echo number_format($rate['rate_per_gram'], 2); ?></strong>
                                                </td>
                                                <td class="text-end">₹<?php echo number_format($rate['rate_per_gram'] * 8, 2); ?></td>
                                                <td class="text-end">₹<?php echo number_format($rate['rate_per_gram'] * 10, 2); ?></td>
                                                <td><?php echo h($rate['remarks'] ?? '-'); ?></td>
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
                                            <td colspan="<?php echo $isAdmin ? 10 : 9; ?>" class="text-center text-muted">
                                                No gold rates found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Gold Value Calculator -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calculator"></i> Gold Value Calculator
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="calculator-box">
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="form-label">Weight (grams)</label>
                                    <input type="number" step="0.001" id="calc_weight" class="form-control" placeholder="Enter weight in grams">
                                    <div class="info-text">or enter sovereigns below</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Sovereigns (1 Sov = 8g)</label>
                                    <input type="number" step="0.001" id="calc_sovereign" class="form-control" placeholder="Enter sovereigns">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Carat / Purity</label>
                                    <select id="calc_carat" class="form-select">
                                        <?php foreach ($todayRates as $carat => $data): ?>
                                        <option value="<?php echo $carat; ?>" data-rate="<?php echo $data['rate']; ?>">
                                            <?php echo $carat; ?> (<?php echo $data['purity']; ?>% purity)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Rate per Gram (₹)</label>
                                    <input type="number" step="0.01" id="calc_rate" class="form-control" placeholder="Or enter custom rate">
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="result-box">
                                        <small>Estimated Gold Value</small>
                                        <h2 id="calc_result">₹0.00</h2>
                                        <small id="calc_breakdown" class="text-white-50"></small>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="alert alert-light text-dark">
                                        <strong>Making Charges:</strong> Usually 8-15% of gold value<br>
                                        <strong>GST:</strong> 3% on gold value + 18% on making charges
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-light text-dark">
                                        <strong>Quick Reference:</strong><br>
                                        1 Sovereign = 8 grams<br>
                                        1 Tola = 11.66 grams<br>
                                        1 Ounce = 31.103 grams
                                    </div>
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

    // Auto-calculate purity based on carat selection
    $('#carat_select').change(function() {
        var carat = $(this).val();
        var purity = {
            '24K': 99.90,
            '22K': 91.67,
            '18K': 75.00,
            '14K': 58.33,
            '10K': 41.67
        };
        $('#purity_percent').val(purity[carat]);
    });
    
    // Calculate sovereign rate
    $('#rate_per_gram').on('keyup change', function() {
        var rate = parseFloat($(this).val()) || 0;
        $('#rate_per_sovereign').val('₹' + (rate * 8).toFixed(2));
    });
    
    // Trigger on load
    $('#carat_select').trigger('change');
    
    // Gold Value Calculator
    function calculateGoldValue() {
        var weight = parseFloat($('#calc_weight').val()) || 0;
        var sovereign = parseFloat($('#calc_sovereign').val()) || 0;
        var totalWeight = weight + (sovereign * 8);
        var rate = parseFloat($('#calc_rate').val());
        
        // If rate not provided, use selected carat's rate
        if (isNaN(rate) || rate === 0) {
            var selectedCarat = $('#calc_carat option:selected');
            rate = parseFloat(selectedCarat.data('rate')) || 0;
        }
        
        var value = totalWeight * rate;
        $('#calc_result').text('₹' + value.toFixed(2));
        
        if (totalWeight > 0) {
            $('#calc_breakdown').text(totalWeight.toFixed(3) + ' grams × ₹' + rate.toFixed(2) + ' = ₹' + value.toFixed(2));
        } else {
            $('#calc_breakdown').text('');
        }
    }
    
    // Sync weight and sovereign fields
    $('#calc_weight, #calc_sovereign, #calc_carat, #calc_rate').on('keyup change', function() {
        calculateGoldValue();
    });
    
    // When weight changes, clear sovereign and vice versa
    $('#calc_weight').on('keyup', function() {
        if ($(this).val()) {
            $('#calc_sovereign').val('');
        }
        calculateGoldValue();
    });
    
    $('#calc_sovereign').on('keyup', function() {
        if ($(this).val()) {
            $('#calc_weight').val('');
        }
        calculateGoldValue();
    });
    
    // Update rate when carat changes
    $('#calc_carat').change(function() {
        var selected = $(this).find('option:selected');
        var rate = selected.data('rate');
        $('#calc_rate').val(rate);
        calculateGoldValue();
    });
    
    // Trigger calculator on load
    $('#calc_carat').trigger('change');
});
</script>

</body>
</html>