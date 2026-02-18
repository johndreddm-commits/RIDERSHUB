<?php
session_start();
require_once 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Initialize variables
$report_data = [];
$report_type = isset($_POST['report_type']) ? mysqli_real_escape_string($conn, $_POST['report_type']) : (isset($_GET['type']) ? mysqli_real_escape_string($conn, $_GET['type']) : 'inventory');
$start_date = isset($_POST['start_date']) ? mysqli_real_escape_string($conn, $_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_POST['end_date']) ? mysqli_real_escape_string($conn, $_POST['end_date']) : date('Y-m-d');
$summary = [];

// Handle report generation
if(isset($_POST['generate_report']) || isset($_GET['generate'])) {
    
    // Build query based on report type
    if($report_type == 'inventory') {
        // Inventory Report with available stock calculation
        $query = "SELECT 
                    p.id, 
                    p.name, 
                    p.product_code, 
                    b.brand_name, 
                    p.price,
                    COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                    COALESCE(i.reserved_quantity, 0) as reserved_stock,
                    GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock,
                    COALESCE(i.min_stock, 5) as min_stock,
                    p.status,
                    p.updated_at
                  FROM products p
                  LEFT JOIN brands b ON p.brand_id = b.id
                  LEFT JOIN inventory i ON p.id = i.product_id
                  WHERE p.updated_at BETWEEN '$start_date' AND '$end_date 23:59:59'
                  ORDER BY p.name";
        
        // Get summary statistics
        $summary_query = "SELECT 
                            COUNT(DISTINCT p.id) as total_products,
                            SUM(COALESCE(i.quantity, p.quantity, p.stock, 0)) as total_inventory_value,
                            SUM(COALESCE(i.reserved_quantity, 0)) as total_reserved,
                            SUM(GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0))) as total_available,
                            SUM(CASE WHEN GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) <= COALESCE(i.min_stock, 5) 
                                 AND GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) > 0 THEN 1 ELSE 0 END) as low_stock_count,
                            SUM(CASE WHEN GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) = 0 THEN 1 ELSE 0 END) as out_of_stock_count
                          FROM products p
                          LEFT JOIN inventory i ON p.id = i.product_id
                          WHERE p.status = 'active'";
        
    } elseif($report_type == 'reservations') {
        // Reservations Report with inventory impact
        $query = "SELECT 
                    r.ticket_number, 
                    r.customer_name, 
                    r.phone, 
                    p.name as product_name,
                    r.selected_color,
                    r.selected_size,
                    r.quantity,
                    r.pickup_date, 
                    r.status,
                    r.created_at,
                    r.confirmed_at,
                    r.cancelled_at
                  FROM reservations r
                  JOIN products p ON r.product_id = p.id
                  WHERE r.created_at BETWEEN '$start_date' AND '$end_date 23:59:59'
                  ORDER BY r.created_at DESC";
        
        // Get summary statistics
        $summary_query = "SELECT 
                            COUNT(*) as total_reservations,
                            SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                            SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed_count,
                            SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled_count,
                            SUM(CASE WHEN status = 'CONFIRMED' THEN quantity ELSE 0 END) as total_confirmed_units
                          FROM reservations
                          WHERE created_at BETWEEN '$start_date' AND '$end_date 23:59:59'";
        
    } elseif($report_type == 'inventory_movements') {
        // Inventory Movements Report (from logs)
        $query = "SELECT 
                    l.*,
                    p.name as product_name,
                    p.product_code,
                    b.brand_name
                  FROM inventory_logs l
                  JOIN products p ON l.product_id = p.id
                  LEFT JOIN brands b ON p.brand_id = b.id
                  WHERE l.created_at BETWEEN '$start_date' AND '$end_date 23:59:59'
                  ORDER BY l.created_at DESC
                  LIMIT 1000";
        
        // Get summary statistics
        $summary_query = "SELECT 
                            COUNT(*) as total_movements,
                            SUM(CASE WHEN action LIKE '%in%' OR action LIKE '%add%' OR action LIKE '%restore%' THEN quantity ELSE 0 END) as total_in,
                            SUM(CASE WHEN action LIKE '%out%' OR action LIKE '%deduct%' OR action LIKE '%confirm%' THEN quantity ELSE 0 END) as total_out,
                            COUNT(DISTINCT product_id) as products_affected
                          FROM inventory_logs
                          WHERE created_at BETWEEN '$start_date' AND '$end_date 23:59:59'";
        
    } elseif($report_type == 'stock_valuation') {
        // Stock Valuation Report
        $query = "SELECT 
                    p.id,
                    p.name,
                    p.product_code,
                    b.brand_name,
                    p.price,
                    COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                    COALESCE(i.reserved_quantity, 0) as reserved_stock,
                    GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock,
                    (COALESCE(i.quantity, p.quantity, p.stock, 0) * p.price) as total_value,
                    (GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) * p.price) as available_value,
                    (COALESCE(i.reserved_quantity, 0) * p.price) as reserved_value
                  FROM products p
                  LEFT JOIN brands b ON p.brand_id = b.id
                  LEFT JOIN inventory i ON p.id = i.product_id
                  WHERE p.status = 'active'
                  ORDER BY total_value DESC";
        
        // Get summary statistics
        $summary_query = "SELECT 
                            SUM(COALESCE(i.quantity, p.quantity, p.stock, 0) * p.price) as total_inventory_value,
                            SUM(GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) * p.price) as total_available_value,
                            SUM(COALESCE(i.reserved_quantity, 0) * p.price) as total_reserved_value,
                            COUNT(*) as total_products
                          FROM products p
                          LEFT JOIN inventory i ON p.id = i.product_id
                          WHERE p.status = 'active'";
    }
    
    $result = mysqli_query($conn, $query);
    if($result) {
        while($row = mysqli_fetch_assoc($result)) {
            $report_data[] = $row;
        }
    }
    
    // Get summary if available
    if(isset($summary_query)) {
        $summary_result = mysqli_query($conn, $summary_query);
        $summary = mysqli_fetch_assoc($summary_result);
    }
    
    // Store in session for export
    $_SESSION['report_data'] = $report_data;
    $_SESSION['report_summary'] = $summary;
    $_SESSION['report_type'] = $report_type;
    $_SESSION['start_date'] = $start_date;
    $_SESSION['end_date'] = $end_date;
}

// Handle PDF export
if(isset($_GET['export_pdf'])) {
    // Redirect to PDF generator (you'll need to create this)
    header("Location: generate_pdf.php?type=$report_type&start=$start_date&end=$end_date");
    exit();
}

// Get report types for dropdown
$report_types = [
    'inventory' => 'Inventory Report',
    'reservations' => 'Reservations Report',
    'inventory_movements' => 'Inventory Movements',
    'stock_valuation' => 'Stock Valuation'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - KB Riders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .report-card {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .summary-card .value {
            font-size: 2rem;
            font-weight: bold;
        }
        .summary-card .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .quick-report-btn {
            background: rgba(255,0,0,0.1);
            border: 1px solid red;
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .quick-report-btn:hover {
            background: red;
            color: white;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .status-pending { background: #ffc107; color: #000; }
        .status-confirmed { background: #28a745; color: #fff; }
        .status-cancelled { background: #dc3545; color: #fff; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>
                        <i class="bi bi-file-earmark-bar-graph"></i> Reports
                    </h1>
                    <div>
                        <a href="inventory.php" class="btn btn-outline-info">
                            <i class="bi bi-clipboard-data"></i> Inventory
                        </a>
                        <a href="reservations.php" class="btn btn-outline-warning">
                            <i class="bi bi-calendar-check"></i> Reservations
                        </a>
                    </div>
                </div>

                <!-- Quick Report Links -->
                <div class="mb-4">
                    <a href="?type=inventory&generate=1&start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="quick-report-btn">
                        <i class="bi bi-box"></i> Last 30 Days Inventory
                    </a>
                    <a href="?type=reservations&generate=1&start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="quick-report-btn">
                        <i class="bi bi-calendar-check"></i> Last 30 Days Reservations
                    </a>
                    <a href="?type=inventory_movements&generate=1&start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="quick-report-btn">
                        <i class="bi bi-arrow-left-right"></i> Last 7 Days Movements
                    </a>
                    <a href="?type=stock_valuation&generate=1" class="quick-report-btn">
                        <i class="bi bi-currency-dollar"></i> Stock Valuation
                    </a>
                </div>

                <!-- Report Form -->
                <div class="card report-card">
                    <div class="card-header">
                        <h5 class="mb-0">Generate Custom Report</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="reportForm">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="report_type" class="form-label">Report Type</label>
                                        <select class="form-select" id="report_type" name="report_type" required>
                                            <option value="">Select Report Type</option>
                                            <?php foreach($report_types as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo $report_type == $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" 
                                               value="<?php echo $start_date; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" 
                                               value="<?php echo $end_date; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <div class="mb-3 w-100">
                                        <button type="submit" name="generate_report" class="btn btn-primary w-100">
                                            <i class="bi bi-search"></i> Generate
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Report Display -->
                <?php if(!empty($report_data)): ?>
                
                <!-- Summary Section -->
                <?php if(!empty($summary)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <h5 class="mb-3">Summary Statistics</h5>
                    </div>
                    
                    <?php if($report_type == 'inventory'): ?>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="value"><?php echo number_format($summary['total_products']); ?></div>
                            <div class="label">Total Products</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="value">₱<?php echo number_format($summary['total_inventory_value'], 2); ?></div>
                            <div class="label">Total Inventory Value</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="value"><?php echo number_format($summary['total_available']); ?></div>
                            <div class="label">Available Units</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="value"><?php echo number_format($summary['total_reserved']); ?></div>
                            <div class="label">Reserved Units</div>
                        </div>
                    </div>
                    
                    <?php elseif($report_type == 'reservations'): ?>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="value"><?php echo number_format($summary['total_reservations']); ?></div>
                            <div class="label">Total Reservations</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card" style="background: #ffc107; color: #000;">
                            <div class="value"><?php echo number_format($summary['pending_count']); ?></div>
                            <div class="label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card" style="background: #28a745;">
                            <div class="value"><?php echo number_format($summary['confirmed_count']); ?></div>
                            <div class="label">Confirmed</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card" style="background: #dc3545;">
                            <div class="value"><?php echo number_format($summary['confirmed_count'] ? $summary['total_confirmed_units'] : 0); ?></div>
                            <div class="label">Units Reserved</div>
                        </div>
                    </div>
                    
                    <?php elseif($report_type == 'inventory_movements'): ?>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="value"><?php echo number_format($summary['total_movements']); ?></div>
                            <div class="label">Total Movements</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card" style="background: #28a745;">
                            <div class="value"><?php echo number_format($summary['total_in']); ?></div>
                            <div class="label">Stock In</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card" style="background: #dc3545;">
                            <div class="value"><?php echo number_format($summary['total_out']); ?></div>
                            <div class="label">Stock Out</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card" style="background: #17a2b8;">
                            <div class="value"><?php echo number_format($summary['products_affected']); ?></div>
                            <div class="label">Products Affected</div>
                        </div>
                    </div>
                    
                    <?php elseif($report_type == 'stock_valuation'): ?>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="value">₱<?php echo number_format($summary['total_inventory_value'] ?? 0, 2); ?></div>
                            <div class="label">Total Inventory Value</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card" style="background: #28a745;">
                            <div class="value">₱<?php echo number_format($summary['total_available_value'] ?? 0, 2); ?></div>
                            <div class="label">Available Value</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card" style="background: #ffc107; color: #000;">
                            <div class="value">₱<?php echo number_format($summary['total_reserved_value'] ?? 0, 2); ?></div>
                            <div class="label">Reserved Value</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Report Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <?php echo $report_types[$report_type]; ?>
                            <small class="text-muted ms-2">
                                (<?php echo $start_date ? date('M d, Y', strtotime($start_date)) : 'All time'; ?> 
                                to <?php echo $end_date ? date('M d, Y', strtotime($end_date)) : 'present'; ?>)
                            </small>
                        </h5>
                        <div>
                            <button class="btn btn-sm btn-success me-2" id="exportPdf">
                                <i class="bi bi-file-pdf"></i> PDF
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover report-table" id="reportTable">
                                <thead>
                                    <tr>
                                        <?php if($report_type == 'inventory'): ?>
                                            <th>ID</th>
                                            <th>Product Code</th>
                                            <th>Product Name</th>
                                            <th>Brand</th>
                                            <th>Price</th>
                                            <th>Total Stock</th>
                                            <th>Reserved</th>
                                            <th>Available</th>
                                            <th>Min Stock</th>
                                            <th>Status</th>
                                            <th>Last Updated</th>
                                        <?php elseif($report_type == 'reservations'): ?>
                                            <th>Ticket No.</th>
                                            <th>Customer</th>
                                            <th>Phone</th>
                                            <th>Product</th>
                                            <th>Color/Size</th>
                                            <th>Qty</th>
                                            <th>Pickup Date</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Confirmed/Cancelled</th>
                                        <?php elseif($report_type == 'inventory_movements'): ?>
                                            <th>Date/Time</th>
                                            <th>Product</th>
                                            <th>Brand</th>
                                            <th>Action</th>
                                            <th>Quantity</th>
                                            <th>Reference</th>
                                        <?php elseif($report_type == 'stock_valuation'): ?>
                                            <th>ID</th>
                                            <th>Product Code</th>
                                            <th>Product Name</th>
                                            <th>Brand</th>
                                            <th>Price</th>
                                            <th>Total Stock</th>
                                            <th>Reserved</th>
                                            <th>Available</th>
                                            <th>Total Value</th>
                                            <th>Available Value</th>
                                            <th>Reserved Value</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($report_data as $row): ?>
                                    <tr>
                                        <?php if($report_type == 'inventory'): ?>
                                            <td><?php echo $row['id']; ?></td>
                                            <td><code><?php echo $row['product_code'] ?: 'N/A'; ?></code></td>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['brand_name'] ?: 'N/A'); ?></td>
                                            <td>₱<?php echo number_format($row['price'], 2); ?></td>
                                            <td><?php echo $row['total_stock']; ?></td>
                                            <td class="text-warning"><?php echo $row['reserved_stock']; ?></td>
                                            <td class="text-success"><?php echo $row['available_stock']; ?></td>
                                            <td><?php echo $row['min_stock']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $row['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                    <?php echo ucfirst($row['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $row['updated_at'] ? date('M d, Y', strtotime($row['updated_at'])) : 'N/A'; ?></td>
                                        
                                        <?php elseif($report_type == 'reservations'): ?>
                                            <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td><?php echo $row['phone']; ?></td>
                                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                            <td>
                                                <?php echo $row['selected_color'] ?: 'N/A'; ?> / 
                                                <?php echo $row['selected_size'] ?: 'N/A'; ?>
                                            </td>
                                            <td><?php echo $row['quantity']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($row['pickup_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                                    <?php echo $row['status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                            <td>
                                                <?php 
                                                if($row['status'] == 'CONFIRMED' && $row['confirmed_at']) {
                                                    echo date('M d, Y', strtotime($row['confirmed_at']));
                                                } elseif($row['status'] == 'CANCELLED' && $row['cancelled_at']) {
                                                    echo date('M d, Y', strtotime($row['cancelled_at']));
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                        
                                        <?php elseif($report_type == 'inventory_movements'): ?>
                                            <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['brand_name'] ?: 'N/A'); ?></td>
                                            <td>
                                                <?php
                                                $badge_class = 'secondary';
                                                $action = $row['action'];
                                                if(strpos($action, 'in') !== false || strpos($action, 'add') !== false || strpos($action, 'restore') !== false) {
                                                    $badge_class = 'success';
                                                } elseif(strpos($action, 'out') !== false || strpos($action, 'deduct') !== false || strpos($action, 'confirm') !== false) {
                                                    $badge_class = 'danger';
                                                } elseif(strpos($action, 'update') !== false) {
                                                    $badge_class = 'primary';
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class; ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $action)); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $row['quantity']; ?></td>
                                            <td>
                                                <?php if($row['reference_id'] && strpos($row['action'], 'reservation') !== false): ?>
                                                <a href="reservations.php?id=<?php echo $row['reference_id']; ?>" class="text-info">
                                                    View Reservation
                                                </a>
                                                <?php else: ?>
                                                -
                                                <?php endif; ?>
                                            </td>
                                        
                                        <?php elseif($report_type == 'stock_valuation'): ?>
                                            <td><?php echo $row['id']; ?></td>
                                            <td><code><?php echo $row['product_code'] ?: 'N/A'; ?></code></td>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['brand_name'] ?: 'N/A'); ?></td>
                                            <td>₱<?php echo number_format($row['price'], 2); ?></td>
                                            <td><?php echo $row['total_stock']; ?></td>
                                            <td class="text-warning"><?php echo $row['reserved_stock']; ?></td>
                                            <td class="text-success"><?php echo $row['available_stock']; ?></td>
                                            <td>₱<?php echo number_format($row['total_value'], 2); ?></td>
                                            <td>₱<?php echo number_format($row['available_value'], 2); ?></td>
                                            <td>₱<?php echo number_format($row['reserved_value'], 2); ?></td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if($report_type == 'stock_valuation'): ?>
                                <tfoot>
                                    <tr class="fw-bold">
                                        <td colspan="8" class="text-end">TOTAL:</td>
                                        <td>₱<?php echo number_format($summary['total_inventory_value'] ?? 0, 2); ?></td>
                                        <td>₱<?php echo number_format($summary['total_available_value'] ?? 0, 2); ?></td>
                                        <td>₱<?php echo number_format($summary['total_reserved_value'] ?? 0, 2); ?></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                        
                        <div class="text-muted mt-3">
                            <small>
                                Total Records: <?php echo count($report_data); ?> | 
                                Generated: <?php echo date('M d, Y h:i A'); ?>
                            </small>
                        </div>
                    </div>
                </div>
                <?php elseif(isset($_POST['generate_report']) || isset($_GET['generate'])): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No records found for the selected date range.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // PDF Export
        document.getElementById('exportPdf')?.addEventListener('click', function() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape');
            
            // Report title
            const reportType = document.getElementById('report_type')?.options[document.getElementById('report_type')?.selectedIndex]?.text || 'Report';
            doc.setFontSize(16);
            doc.text('KB Riders - ' + reportType, 14, 15);
            doc.setFontSize(10);
            doc.text('Generated: ' + new Date().toLocaleString(), 14, 22);
            doc.text('Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>', 14, 28);
            
            // Get table data
            const table = document.getElementById('reportTable');
            if(table) {
                // Get headers (skip first column if ID)
                const headers = [];
                const headerRow = table.querySelectorAll('thead th');
                let startCol = 0;
                
                headerRow.forEach((th, index) => {
                    // Skip ID column for better fit
                    if(index > 0 || <?php echo $report_type == 'inventory' ? 'false' : 'true'; ?>) {
                        headers.push(th.textContent.trim());
                    } else {
                        startCol = 1;
                    }
                });
                
                // Get data rows
                const data = [];
                table.querySelectorAll('tbody tr').forEach(tr => {
                    const rowData = [];
                    const cells = tr.querySelectorAll('td');
                    for(let i = startCol; i < cells.length; i++) {
                        // Remove badge HTML from status cells
                        let text = cells[i].textContent.trim();
                        const badge = cells[i].querySelector('.badge, .status-badge');
                        if(badge) {
                            text = badge.textContent.trim();
                        }
                        // Remove currency symbols for cleaner display
                        text = text.replace('₱', '').replace(',', '');
                        rowData.push(text);
                    }
                    data.push(rowData);
                });
                
                // Create table
                doc.autoTable({
                    head: [headers],
                    body: data,
                    startY: 35,
                    styles: { fontSize: 8 },
                    headStyles: { fillColor: [220, 53, 69] }
                });
                
                // Save PDF
                doc.save('KB_Riders_' + reportType.replace(' ', '_') + '_' + new Date().toISOString().split('T')[0] + '.pdf');
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>