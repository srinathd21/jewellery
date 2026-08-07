<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);
$currentPage = 'metal-rates';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Get all pawn categories
$categories = [];
$cat_sql = "SELECT * FROM pawn_categories WHERE business_id = $business_id AND status = 'active' ORDER BY category_name";
$cat_res = $conn->query($cat_sql);
if ($cat_res) {
    while ($row = $cat_res->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Get current metal rates
$metal_rates = [];
$rate_sql = "SELECT * FROM metal_rates WHERE business_id = $business_id ORDER BY metal_type, purity";
$rate_res = $conn->query($rate_sql);
if ($rate_res) {
    while ($row = $rate_res->fetch_assoc()) {
        $metal_rates[$row['metal_type']][$row['purity']] = $row;
    }
}

// Process form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_rates') {
            // Update multiple rates
            $metal_types = $_POST['metal_type'] ?? [];
            $purities = $_POST['purity'] ?? [];
            $rates = $_POST['rate_per_gram'] ?? [];
            $effective_date = $conn->real_escape_string($_POST['effective_date'] ?? date('Y-m-d'));
            
            $conn->begin_transaction();
            $success_count = 0;
            $error_count = 0;
            
            try {
                for ($i = 0; $i < count($metal_types); $i++) {
                    if (empty($metal_types[$i]) || empty($purities[$i])) continue;
                    
                    $metal_type = $conn->real_escape_string($metal_types[$i]);
                    $purity = $conn->real_escape_string($purities[$i]);
                    $rate_per_gram = (float)($rates[$i] ?? 0);
                    
                    // Check if exists
                    $check_sql = "SELECT id FROM metal_rates WHERE business_id = $business_id AND metal_type = '$metal_type' AND purity = '$purity'";
                    $check_res = $conn->query($check_sql);
                    
                    if ($check_res && $check_res->num_rows > 0) {
                        $row = $check_res->fetch_assoc();
                        $update_sql = "UPDATE metal_rates SET rate_per_gram = $rate_per_gram, effective_date = '$effective_date', updated_at = NOW() WHERE id = {$row['id']}";
                        if ($conn->query($update_sql)) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    } else {
                        $insert_sql = "INSERT INTO metal_rates (business_id, metal_type, purity, rate_per_gram, effective_date, created_by, created_at) 
                                       VALUES ($business_id, '$metal_type', '$purity', $rate_per_gram, '$effective_date', $created_by, NOW())";
                        if ($conn->query($insert_sql)) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    }
                }
                
                if ($error_count == 0) {
                    $conn->commit();
                    $success_msg = "$success_count rate(s) updated successfully!";
                } else {
                    $conn->rollback();
                    $error_msg = "Failed to update $error_count rate(s). Please try again.";
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'add_category_rate') {
            $metal_type = $conn->real_escape_string($_POST['metal_type'] ?? 'Gold');
            $purity = $conn->real_escape_string($_POST['purity'] ?? '');
            $rate_per_gram = (float)($_POST['rate_per_gram'] ?? 0);
            $effective_date = $conn->real_escape_string($_POST['effective_date'] ?? date('Y-m-d'));
            
            if (empty($purity)) {
                $error_msg = "Purity is required";
            } elseif ($rate_per_gram <= 0) {
                $error_msg = "Rate per gram must be greater than 0";
            } else {
                $check_sql = "SELECT id FROM metal_rates WHERE business_id = $business_id AND metal_type = '$metal_type' AND purity = '$purity'";
                $check_res = $conn->query($check_sql);
                
                if ($check_res && $check_res->num_rows > 0) {
                    $error_msg = "Rate for $metal_type - $purity already exists! Use the update form instead.";
                } else {
                    $insert_sql = "INSERT INTO metal_rates (business_id, metal_type, purity, rate_per_gram, effective_date, created_by, created_at) 
                                   VALUES ($business_id, '$metal_type', '$purity', $rate_per_gram, '$effective_date', $created_by, NOW())";
                    if ($conn->query($insert_sql)) {
                        $success_msg = "New rate added successfully!";
                    } else {
                        $error_msg = "Failed to add rate: " . $conn->error;
                    }
                }
            }
        }
    }
    
    // Refresh data after update
    $rate_res = $conn->query($rate_sql);
    if ($rate_res) {
        $metal_rates = [];
        while ($row = $rate_res->fetch_assoc()) {
            $metal_rates[$row['metal_type']][$row['purity']] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Metal Rate Settings | Pawn Broking</title>
<style>
    .rate-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .rate-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .rate-card .card-body { padding: 20px; }
    .rate-display { font-size: 20px; font-weight: 700; color: #2c7da0; }
    .category-badge { background: #e8f4f8; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
    .table-rate-input { width: 150px; }
    .gold-bg { background: linear-gradient(135deg, #ffd70020, #ffb34720); }
    .silver-bg { background: linear-gradient(135deg, #e0e0e020, #c0c0c020); }
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
                    
                    <!-- Page Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-1">Metal Rate Settings</h4>
                                    <p class="text-muted mb-0">Configure rates for different metals and purities</p>
                                </div>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRateModal">
                                    <i class="fas fa-plus"></i> Add New Rate
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($success_msg): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $success_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_msg): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Current Rates Display -->
                    <div class="card rate-card">
                        <div class="card-header">
                            <i class="fas fa-chart-line me-2"></i> Current Metal Rates
                            <span class="float-end"><small class="text-muted">Effective from: <?php echo date('d-m-Y'); ?></small></span>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="ratesForm">
                                <input type="hidden" name="action" value="update_rates">
                                <input type="hidden" name="effective_date" value="<?php echo date('Y-m-d'); ?>">
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="15%">Metal Type</th>
                                                <th width="20%">Purity</th>
                                                <th width="25%">Current Rate (₹/gram)</th>
                                                <th width="25%">Rate (₹/sovereign)</th>
                                                <th width="15%">Last Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $metal_order = ['Gold', 'Silver', 'Platinum', 'Other'];
                                            $has_rates = false;
                                            foreach ($metal_order as $metal): 
                                                if (isset($metal_rates[$metal])):
                                                    foreach ($metal_rates[$metal] as $purity => $rate):
                                                        $has_rates = true;
                                            ?>
                                                <tr class="<?php echo $metal == 'Gold' ? 'gold-bg' : ($metal == 'Silver' ? 'silver-bg' : ''); ?>">
                                                    <td>
                                                        <strong><?php echo h($metal); ?></strong>
                                                        <input type="hidden" name="metal_type[]" value="<?php echo h($metal); ?>">
                                                    </td>
                                                    <td>
                                                        <?php echo h($purity); ?>
                                                        <input type="hidden" name="purity[]" value="<?php echo h($purity); ?>">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" name="rate_per_gram[]" class="form-control table-rate-input" 
                                                               value="<?php echo money($rate['rate_per_gram']); ?>" required>
                                                    </td>
                                                    <td>
                                                        <strong>₹<?php echo money($rate['rate_per_gram'] * 8); ?></strong>
                                                    </td>
                                                    <td>
                                                        <small><?php echo date('d-m-Y', strtotime($rate['updated_at'] ?? $rate['created_at'])); ?></small>
                                                    </td>
                                                </tr>
                                            <?php 
                                                    endforeach;
                                                endif;
                                            endforeach;
                                            ?>
                                            
                                            <?php if (!$has_rates): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted py-4">
                                                        No rates configured yet. Click "Add New Rate" to get started.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if ($has_rates): ?>
                                <div class="text-end mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update All Rates
                                    </button>
                                </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Category Wise Pawn Value Reference -->
                    <div class="card rate-card">
                        <div class="card-header">
                            <i class="fas fa-tags me-2"></i> Category Wise Pawn Value Reference
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Category Name</th>
                                            <th>Code</th>
                                            <th>Default Interest Rate</th>
                                            <th>Default Pawn Value (₹/gram)</th>
                                            <th>Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($categories)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">No pawn categories found. Create categories first.</td>
                                            </tr>
                                        <?php else: foreach ($categories as $cat): ?>
                                            <tr>
                                                <td><strong><?php echo h($cat['category_name']); ?></strong></td>
                                                <td><?php echo h($cat['category_code']); ?></td>
                                                <td><?php echo h($cat['default_interest_rate']); ?>%</td>
                                                <td>₹<?php echo money($cat['default_pawn_value_per_gram'] ?? 0); ?></td>
                                                <td><small><?php echo h($cat['description']); ?></small></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <!-- Add Rate Modal -->
    <div class="modal fade" id="addRateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_category_rate">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Metal Rate</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Metal Type</label>
                            <select name="metal_type" class="form-select" required>
                                <option value="Gold">Gold</option>
                                <option value="Silver">Silver</option>
                                <option value="Platinum">Platinum</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Purity</label>
                            <input type="text" name="purity" class="form-control" placeholder="e.g., 24K, 22K, 916, 925" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rate per Gram (₹)</label>
                            <input type="number" step="0.01" name="rate_per_gram" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Effective Date</label>
                            <input type="date" name="effective_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Rate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        // Auto-calculate sovereign value display
        document.querySelectorAll('input[name="rate_per_gram[]"]').forEach(input => {
            input.addEventListener('change', function() {
                const row = this.closest('tr');
                const rate = parseFloat(this.value) || 0;
                const sovereignCell = row.cells[3];
                sovereignCell.innerHTML = '<strong>₹' + (rate * 8).toFixed(2) + '</strong>';
            });
        });
    </script>
</body>
</html>