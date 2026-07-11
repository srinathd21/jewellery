<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'report-stock';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}
if (!function_exists('formatWeight')) {
    function formatWeight($weight) { return number_format((float)$weight, 3); }
}

// Get filter parameters
$category_id = (int)($_GET['category_id'] ?? 0);
$stock_type = $_GET['stock_type'] ?? 'all'; // all, low_stock, out_of_stock, in_stock
$purity_filter = $_GET['purity'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'product_name';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$export = $_GET['export'] ?? '';
$format = $_GET['format'] ?? '';

// Build WHERE clause
$where_conditions = ["p.business_id = $business_id", "p.is_active = 1"];

if ($category_id > 0) {
    $where_conditions[] = "p.category_id = $category_id";
}
if ($purity_filter != 'all') {
    $where_conditions[] = "p.purity = '" . $conn->real_escape_string($purity_filter) . "'";
}
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $where_conditions[] = "(p.product_name LIKE '%$search%' OR p.product_code LIKE '%$search%' OR p.design_name LIKE '%$search%' OR p.barcode LIKE '%$search%')";
}

// Stock type filter
if ($stock_type == 'low_stock') {
    $where_conditions[] = "p.current_stock_qty <= p.min_stock_qty AND p.current_stock_qty > 0";
} elseif ($stock_type == 'out_of_stock') {
    $where_conditions[] = "p.current_stock_qty <= 0";
} elseif ($stock_type == 'in_stock') {
    $where_conditions[] = "p.current_stock_qty > 0";
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch stock data
$products = [];
$sql = "SELECT p.*, pc.category_name, pc.gst_percent,
               (p.net_weight * p.current_stock_qty) as total_weight,
               (p.sale_rate * p.current_stock_qty) as stock_value
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE $where_clause
        ORDER BY $sort_by $sort_order";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

// Get categories for filter
$categories = [];
$cat_sql = "SELECT id, category_name FROM product_categories WHERE business_id = $business_id AND is_active = 1 ORDER BY category_name";
$cat_res = $conn->query($cat_sql);
if ($cat_res) {
    while ($row = $cat_res->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Get unique purities for filter
$purities = [];
$purity_sql = "SELECT DISTINCT purity FROM products WHERE business_id = $business_id AND is_active = 1 AND purity != '' ORDER BY purity";
$purity_res = $conn->query($purity_sql);
if ($purity_res) {
    while ($row = $purity_res->fetch_assoc()) {
        $purities[] = $row['purity'];
    }
}

// Calculate summary statistics
$total_products = count($products);
$total_quantity = array_sum(array_column($products, 'current_stock_qty'));
$total_weight = array_sum(array_column($products, 'total_weight'));
$total_value = array_sum(array_column($products, 'stock_value'));
$low_stock_count = count(array_filter($products, function($p) { 
    return $p['current_stock_qty'] <= $p['min_stock_qty'] && $p['current_stock_qty'] > 0; 
}));
$out_of_stock_count = count(array_filter($products, function($p) { 
    return $p['current_stock_qty'] <= 0; 
}));

// Calculate by category
$category_summary = [];
foreach ($products as $product) {
    $cat_name = $product['category_name'] ?? 'Uncategorized';
    if (!isset($category_summary[$cat_name])) {
        $category_summary[$cat_name] = ['count' => 0, 'value' => 0, 'weight' => 0];
    }
    $category_summary[$cat_name]['count']++;
    $category_summary[$cat_name]['value'] += $product['stock_value'];
    $category_summary[$cat_name]['weight'] += $product['total_weight'];
}

// Calculate by purity
$purity_summary = [];
foreach ($products as $product) {
    $purity = $product['purity'] ?: 'N/A';
    if (!isset($purity_summary[$purity])) {
        $purity_summary[$purity] = ['count' => 0, 'value' => 0, 'weight' => 0];
    }
    $purity_summary[$purity]['count']++;
    $purity_summary[$purity]['value'] += $product['stock_value'];
    $purity_summary[$purity]['weight'] += $product['total_weight'];
}

// Handle Export
if ($export == '1') {
    if ($format == 'excel') {
        exportToExcel($products, $total_products, $total_quantity, $total_weight, $total_value, $low_stock_count, $out_of_stock_count);
    } elseif ($format == 'pdf') {
        exportToPDF($conn, $products, $total_products, $total_quantity, $total_weight, $total_value, $low_stock_count, $out_of_stock_count);
    }
    exit;
}

// Export functions
function exportToExcel($products, $total_products, $total_quantity, $total_weight, $total_value, $low_stock_count, $out_of_stock_count) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.xls"');
    
    echo "Stock Report\n";
    echo "Generated On: " . date('d-m-Y H:i:s') . "\n\n";
    
    echo "Product Code\tProduct Name\tCategory\tPurity\tUnit\tCurrent Stock\tMin Stock\tNet Weight(g)\tTotal Weight(g)\tSale Rate\tStock Value\tStatus\n";
    
    foreach ($products as $product) {
        $status = '';
        if ($product['current_stock_qty'] <= 0) {
            $status = 'Out of Stock';
        } elseif ($product['current_stock_qty'] <= $product['min_stock_qty']) {
            $status = 'Low Stock';
        } else {
            $status = 'In Stock';
        }
        
        echo $product['product_code'] . "\t";
        echo $product['product_name'] . "\t";
        echo ($product['category_name'] ?? 'Uncategorized') . "\t";
        echo $product['purity'] . "\t";
        echo $product['unit'] . "\t";
        echo number_format($product['current_stock_qty'], 3) . "\t";
        echo number_format($product['min_stock_qty'], 3) . "\t";
        echo number_format($product['net_weight'], 3) . "\t";
        echo number_format($product['total_weight'], 3) . "\t";
        echo number_format($product['sale_rate'], 2) . "\t";
        echo number_format($product['stock_value'], 2) . "\t";
        echo $status . "\n";
    }
    
    echo "\n\nSUMMARY\n";
    echo "Total Products:\t" . $total_products . "\n";
    echo "Total Quantity:\t" . number_format($total_quantity, 3) . "\n";
    echo "Total Weight:\t" . number_format($total_weight, 3) . " g\n";
    echo "Total Stock Value:\t" . number_format($total_value, 2) . "\n";
    echo "Low Stock Items:\t" . $low_stock_count . "\n";
    echo "Out of Stock Items:\t" . $out_of_stock_count . "\n";
}

function exportToPDF($conn, $products, $total_products, $total_quantity, $total_weight, $total_value, $low_stock_count, $out_of_stock_count) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF_Stock extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'Stock Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function StockTable($header, $data) {
            $this->SetFillColor(50, 50, 100);
            $this->SetTextColor(255);
            $this->SetFont('Arial', 'B', 8);
            
            $w = array(30, 45, 25, 25, 30, 30, 25);
            for($i=0; $i<count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            $this->SetFillColor(255);
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 7);
            $fill = false;
            
            foreach($data as $row) {
                $this->Cell($w[0], 6, substr($row['product_code'], 0, 12), 1, 0, 'L', $fill);
                $this->Cell($w[1], 6, substr($row['product_name'], 0, 25), 1, 0, 'L', $fill);
                $this->Cell($w[2], 6, number_format($row['current_stock_qty'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[3], 6, number_format($row['total_weight'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[4], 6, number_format($row['sale_rate'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[5], 6, number_format($row['stock_value'], 0), 1, 0, 'R', $fill);
                
                $status = '';
                if ($row['current_stock_qty'] <= 0) {
                    $status = 'Out';
                } elseif ($row['current_stock_qty'] <= $row['min_stock_qty']) {
                    $status = 'Low';
                } else {
                    $status = 'OK';
                }
                $this->Cell($w[6], 6, $status, 1, 0, 'C', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        }
    }
    
    $pdf = new PDF_Stock('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    
    // Summary
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 6, 'Total Products:', 0, 0);
    $pdf->Cell(40, 6, $total_products, 0, 1);
    $pdf->Cell(60, 6, 'Total Quantity:', 0, 0);
    $pdf->Cell(40, 6, number_format($total_quantity, 0), 0, 1);
    $pdf->Cell(60, 6, 'Total Weight:', 0, 0);
    $pdf->Cell(40, 6, number_format($total_weight, 2) . ' g', 0, 1);
    $pdf->Cell(60, 6, 'Total Stock Value:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_value, 2), 0, 1);
    $pdf->Cell(60, 6, 'Low Stock Items:', 0, 0);
    $pdf->Cell(40, 6, $low_stock_count, 0, 1);
    $pdf->Cell(60, 6, 'Out of Stock:', 0, 0);
    $pdf->Cell(40, 6, $out_of_stock_count, 0, 1);
    $pdf->Ln(8);
    
    // Table Header
    $header = array('Code', 'Product Name', 'Qty', 'Weight(g)', 'Rate', 'Value', 'Status');
    
    $pdf->StockTable($header, $products);
    $pdf->Output('D', 'stock_report_' . date('Y-m-d') . '.pdf');
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Stock Report | Reports</title>
<style>
    .report-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .report-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .report-card .card-body { padding: 20px; }
    .filter-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
    .summary-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white; text-align: center; }
    .summary-value { font-size: 28px; font-weight: 700; }
    .summary-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
    .status-instock { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-lowstock { background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-outofstock { background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .btn-export { margin-right: 10px; }
    .sort-link { cursor: pointer; color: #4b5563; text-decoration: none; }
    .sort-link:hover { color: #4f46e5; }
    .sort-active { color: #4f46e5; font-weight: 600; }
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
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <h4 class="mb-1">Stock Report</h4>
                                    <p class="text-muted mb-0">View and export current stock status with filtering</p>
                                </div>
                                <div>
                                    <a href="stock-overview.php" class="btn btn-outline-info">
                                        <i class="fas fa-warehouse"></i> Stock Overview
                                    </a>
                                    <a href="stock-adjustment.php" class="btn btn-outline-warning">
                                        <i class="fas fa-sliders-h"></i> Stock Adjustment
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" id="reportForm" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Purity</label>
                                <select name="purity" class="form-select">
                                    <option value="all">All Purities</option>
                                    <?php foreach ($purities as $pur): ?>
                                        <option value="<?php echo h($pur); ?>" <?php echo $purity_filter == $pur ? 'selected' : ''; ?>>
                                            <?php echo h($pur); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Stock Status</label>
                                <select name="stock_type" class="form-select">
                                    <option value="all" <?php echo $stock_type == 'all' ? 'selected' : ''; ?>>All Stock</option>
                                    <option value="in_stock" <?php echo $stock_type == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                                    <option value="low_stock" <?php echo $stock_type == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                    <option value="out_of_stock" <?php echo $stock_type == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Product name, code, barcode..." value="<?php echo h($search_term); ?>">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <a href="report-stock.php" class="btn btn-secondary w-100">
                                        <i class="fas fa-times"></i> Reset
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-12 mt-3">
                                <div class="text-end">
                                    <button type="button" class="btn btn-success btn-export" onclick="exportReport('excel')">
                                        <i class="fas fa-file-excel"></i> Export to Excel
                                    </button>
                                    <button type="button" class="btn btn-danger btn-export" onclick="exportReport('pdf')">
                                        <i class="fas fa-file-pdf"></i> Export to PDF
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Active Filters Display -->
                        <?php if ($category_id > 0 || $purity_filter != 'all' || $stock_type != 'all' || $search_term): ?>
                            <div class="mt-3">
                                <small class="text-muted">Active Filters:</small>
                                <?php if ($category_id > 0): ?>
                                    <span class="badge bg-info me-1">Category ID: <?php echo $category_id; ?></span>
                                <?php endif; ?>
                                <?php if ($purity_filter != 'all'): ?>
                                    <span class="badge bg-info me-1">Purity: <?php echo h($purity_filter); ?></span>
                                <?php endif; ?>
                                <?php if ($stock_type != 'all'): ?>
                                    <span class="badge bg-info me-1">Status: <?php echo ucfirst(str_replace('_', ' ', $stock_type)); ?></span>
                                <?php endif; ?>
                                <?php if ($search_term): ?>
                                    <span class="badge bg-info me-1">Search: <?php echo h($search_term); ?></span>
                                <?php endif; ?>
                                <span class="text-muted ms-2">Found <?php echo $total_products; ?> products</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value"><?php echo $total_products; ?></div>
                                <div class="summary-label">Total Products</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                <div class="summary-value"><?php echo formatWeight($total_quantity); ?></div>
                                <div class="summary-label">Total Quantity (Pcs)</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <div class="summary-value"><?php echo formatWeight($total_weight); ?> g</div>
                                <div class="summary-label">Total Weight</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <div class="summary-value">₹<?php echo money($total_value); ?></div>
                                <div class="summary-label">Total Stock Value</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stock Status Summary -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-success"><?php echo $total_products - $low_stock_count - $out_of_stock_count; ?></h4>
                                    <p class="mb-0">In Stock</p>
                                    <small class="text-muted">Adequate stock level</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-warning"><?php echo $low_stock_count; ?></h4>
                                    <p class="mb-0">Low Stock</p>
                                    <small class="text-muted">Below minimum level</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-danger"><?php echo $out_of_stock_count; ?></h4>
                                    <p class="mb-0">Out of Stock</p>
                                    <small class="text-muted">Need replenishment</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Category-wise Summary -->
                    <div class="card report-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-chart-pie me-2"></i> Category-wise Summary
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Category</th>
                                            <th class="text-center">Products</th>
                                            <th class="text-end">Total Weight (g)</th>
                                            <th class="text-end">Stock Value (₹)</th>
                                            <th class="text-center">% of Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($category_summary as $cat_name => $data): ?>
                                            <tr>
                                                <td><strong><?php echo h($cat_name); ?></strong></td>
                                                <td class="text-center"><?php echo $data['count']; ?></td>
                                                <td class="text-end"><?php echo formatWeight($data['weight']); ?> g</td>
                                                <td class="text-end">₹<?php echo money($data['value']); ?></td>
                                                <td class="text-center">
                                                    <?php echo $total_value > 0 ? round(($data['value'] / $total_value) * 100, 1) : 0; ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td class="text-end"><strong>Total:</strong></td>
                                            <td class="text-center"><strong><?php echo $total_products; ?></strong></td>
                                            <td class="text-end"><strong><?php echo formatWeight($total_weight); ?> g</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_value); ?></strong></td>
                                            <td class="text-center"><strong>100%</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Purity-wise Summary -->
                    <?php if (!empty($purity_summary) && count($purity_summary) > 1): ?>
                    <div class="card report-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-gem me-2"></i> Purity-wise Summary
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Purity</th>
                                            <th class="text-center">Products</th>
                                            <th class="text-end">Total Weight (g)</th>
                                            <th class="text-end">Stock Value (₹)</th>
                                            <th class="text-center">% of Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($purity_summary as $purity => $data): ?>
                                            <tr>
                                                <td><?php echo h($purity); ?></td>
                                                <td class="text-center"><?php echo $data['count']; ?></td>
                                                <td class="text-end"><?php echo formatWeight($data['weight']); ?> g</td>
                                                <td class="text-end">₹<?php echo money($data['value']); ?></td>
                                                <td class="text-center">
                                                    <?php echo $total_value > 0 ? round(($data['value'] / $total_value) * 100, 1) : 0; ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td class="text-end"><strong>Total:</strong></td>
                                            <td class="text-center"><strong><?php echo $total_products; ?></strong></td>
                                            <td class="text-end"><strong><?php echo formatWeight($total_weight); ?> g</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_value); ?></strong></td>
                                            <td class="text-center"><strong>100%</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Stock Table -->
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Stock Details</h5>
                                <div class="text-muted small">
                                    Showing <?php echo $total_products; ?> products
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>
                                                <a href="javascript:void(0)" class="sort-link <?php echo $sort_by == 'product_code' ? 'sort-active' : ''; ?>" onclick="sortBy('product_code')">
                                                    Product Code
                                                    <?php if ($sort_by == 'product_code'): ?>
                                                        <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="javascript:void(0)" class="sort-link <?php echo $sort_by == 'product_name' ? 'sort-active' : ''; ?>" onclick="sortBy('product_name')">
                                                    Product Name
                                                    <?php if ($sort_by == 'product_name'): ?>
                                                        <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Category</th>
                                            <th>Purity</th>
                                            <th>Unit</th>
                                            <th class="text-end">Current Stock</th>
                                            <th class="text-end">Min Stock</th>
                                            <th class="text-end">Net Wt/Pc</th>
                                            <th class="text-end">Total Weight</th>
                                            <th class="text-end">Sale Rate</th>
                                            <th class="text-end">Stock Value</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($products)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                    No products found matching the filters.
                                                </td>
                                            </tr>
                                        <?php else: foreach ($products as $product): ?>
                                            <?php
                                            $status_class = '';
                                            $status_text = '';
                                            if ($product['current_stock_qty'] <= 0) {
                                                $status_class = 'status-outofstock';
                                                $status_text = 'Out of Stock';
                                            } elseif ($product['current_stock_qty'] <= $product['min_stock_qty']) {
                                                $status_class = 'status-lowstock';
                                                $status_text = 'Low Stock';
                                            } else {
                                                $status_class = 'status-instock';
                                                $status_text = 'In Stock';
                                            }
                                            ?>
                                            <tr>
                                                <td><strong><?php echo h($product['product_code']); ?></strong></td>
                                                <td>
                                                    <?php echo h($product['product_name']); ?>
                                                    <?php if ($product['design_name']): ?>
                                                        <br><small class="text-muted"><?php echo h($product['design_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($product['category_name'] ?? 'Uncategorized'); ?></td>
                                                <td><?php echo h($product['purity']); ?></td>
                                                <td><?php echo h($product['unit']); ?></td>
                                                <td class="text-end">
                                                    <strong><?php echo formatWeight($product['current_stock_qty']); ?></strong>
                                                </td>
                                                <td class="text-end"><?php echo formatWeight($product['min_stock_qty']); ?></td>
                                                <td class="text-end"><?php echo formatWeight($product['net_weight']); ?> g</td>
                                                <td class="text-end"><?php echo formatWeight($product['total_weight']); ?> g</td>
                                                <td class="text-end">₹<?php echo money($product['sale_rate']); ?></td>
                                                <td class="text-end"><strong>₹<?php echo money($product['stock_value']); ?></strong></td>
                                                <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <?php if (!empty($products)): ?>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Totals:</strong></td>
                                            <td class="text-end"><strong><?php echo formatWeight($total_quantity); ?></strong></td>
                                            <td colspan="2"></td>
                                            <td class="text-end"><strong><?php echo formatWeight($total_weight); ?> g</strong></td>
                                            <td></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_value); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        function sortBy(column) {
            let currentSort = '<?php echo $sort_by; ?>';
            let currentOrder = '<?php echo $sort_order; ?>';
            let newOrder = 'ASC';
            
            if (currentSort === column) {
                newOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
            }
            
            const params = new URLSearchParams(window.location.search);
            params.set('sort_by', column);
            params.set('sort_order', newOrder);
            window.location.href = 'report-stock.php?' + params.toString();
        }
        
        function exportReport(format) {
            const form = document.getElementById('reportForm');
            const formData = new FormData(form);
            const params = new URLSearchParams();
            
            for (let [key, value] of formData.entries()) {
                if (value) params.append(key, value);
            }
            params.append('export', '1');
            params.append('format', format);
            
            window.location.href = 'report-stock.php?' + params.toString();
        }
    </script>
</body>
</html>