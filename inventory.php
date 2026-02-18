<?php
session_start();
require_once 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Initialize variables
$brandFilter = isset($_GET['brand_id']) ? intval($_GET['brand_id']) : 0;
$productFilter = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$stockFilter = isset($_GET['stock']) ? $_GET['stock'] : '';
$lowStockThreshold = 5;

// Get all brands for filter dropdown
$brandsQuery = "SELECT id, brand_name FROM brands ORDER BY brand_name";
$brandsResult = $conn->query($brandsQuery);

// Get all products for filter dropdown
$productsQuery = "SELECT id, name, product_code FROM products WHERE status = 'active' ORDER BY name";
$productsResult = $conn->query($productsQuery);

// Build the main query with filters
$sql = "SELECT p.*, 
               b.brand_name,
               COALESCE(i.quantity, p.quantity, p.stock, 0) as current_stock,
               COALESCE(i.reserved_quantity, 0) as reserved_quantity,
               COALESCE(i.min_stock, 5) as min_stock,
               GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock
        FROM products p 
        LEFT JOIN brands b ON p.brand_id = b.id 
        LEFT JOIN inventory i ON p.id = i.product_id 
        WHERE p.status = 'active'";

$conditions = [];
$params = [];
$types = '';

if ($brandFilter > 0) {
    $conditions[] = "p.brand_id = ?";
    $params[] = $brandFilter;
    $types .= 'i';
}

if ($productFilter > 0) {
    $conditions[] = "p.id = ?";
    $params[] = $productFilter;
    $types .= 'i';
}

if (!empty($searchQuery)) {
    $conditions[] = "(p.name LIKE ? OR p.product_code LIKE ? OR p.description LIKE ?)";
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

if (!empty($conditions)) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY 
            CASE 
                WHEN GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) <= 0 THEN 1
                WHEN GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) <= ? THEN 2
                ELSE 3
            END,
            b.brand_name, p.name";

$params[] = $lowStockThreshold;
$types .= 'i';

// Prepare and execute
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $products = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $products = [];
}

// Get reservation data for each product
$product_ids = array_column($products, 'id');
$reservation_data = [];

if (!empty($product_ids)) {
    $ids_string = implode(',', $product_ids);
    $res_query = "SELECT 
                    product_id, 
                    COUNT(*) as total_reservations,
                    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed_count,
                    SUM(CASE WHEN status = 'PENDING' THEN quantity ELSE 0 END) as pending_quantity,
                    SUM(CASE WHEN status = 'CONFIRMED' THEN quantity ELSE 0 END) as confirmed_quantity
                  FROM reservations 
                  WHERE product_id IN ($ids_string)
                  GROUP BY product_id";
    $res_result = $conn->query($res_query);
    if ($res_result) {
        while ($res = $res_result->fetch_assoc()) {
            $reservation_data[$res['product_id']] = $res;
        }
    }
}

// Check if inventory_logs has the new columns
$check_ref_column = $conn->query("SHOW COLUMNS FROM inventory_logs LIKE 'reference_id'");
$has_reference_id = $check_ref_column && $check_ref_column->num_rows > 0;

$check_date_column = $conn->query("SHOW COLUMNS FROM inventory_logs LIKE 'created_at'");
$has_created_at = $check_date_column && $check_date_column->num_rows > 0;

// Get inventory logs for recent activity with safe column checking
if ($has_created_at) {
    $logs_query = "SELECT l.*, p.name as product_name, p.image 
                   FROM inventory_logs l 
                   JOIN products p ON l.product_id = p.id 
                   ORDER BY l.created_at DESC 
                   LIMIT 20";
} else {
    $logs_query = "SELECT l.*, p.name as product_name, p.image 
                   FROM inventory_logs l 
                   JOIN products p ON l.product_id = p.id 
                   ORDER BY l.id DESC 
                   LIMIT 20";
}

$logs_result = $conn->query($logs_query);
$recent_logs = [];
if ($logs_result && $logs_result->num_rows > 0) {
    while ($row = $logs_result->fetch_assoc()) {
        $recent_logs[] = $row;
    }
}

// Handle stock update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $productId = $_POST['product_id'];
    $newQuantity = intval($_POST['quantity']);
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get current reserved quantity
        $reserved_query = "SELECT reserved_quantity FROM inventory WHERE product_id = ?";
        $reserved_stmt = $conn->prepare($reserved_query);
        $reserved_stmt->bind_param('i', $productId);
        $reserved_stmt->execute();
        $reserved_result = $reserved_stmt->get_result();
        $reserved_data = $reserved_result->fetch_assoc();
        $reserved_quantity = $reserved_data['reserved_quantity'] ?? 0;
        
        // Check if new quantity is less than reserved
        if ($newQuantity < $reserved_quantity) {
            throw new Exception("Cannot set stock lower than reserved quantity ($reserved_quantity)");
        }
        
        // Check if inventory record exists
        $checkQuery = "SELECT id FROM inventory WHERE product_id = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param('i', $productId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing
            $updateQuery = "UPDATE inventory SET quantity = ?, last_updated = NOW() WHERE product_id = ?";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param('ii', $newQuantity, $productId);
        } else {
            // Insert new
            $updateQuery = "INSERT INTO inventory (product_id, quantity, reserved_quantity, min_stock, last_updated) 
                            VALUES (?, ?, ?, 5, NOW())";
            $updateStmt = $conn->prepare($updateQuery);
            $defaultReserved = 0;
            $updateStmt->bind_param('iii', $productId, $newQuantity, $defaultReserved);
        }
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update inventory");
        }
        
        // Update products table
        $available_stock = max(0, $newQuantity - $reserved_quantity);
        $updateProductQuery = "UPDATE products SET 
                               stock = ?, 
                               quantity = ?, 
                               current_stock = ?,
                               available_stock = ?
                               WHERE id = ?";
        $updateProductStmt = $conn->prepare($updateProductQuery);
        $updateProductStmt->bind_param('iiiii', $newQuantity, $newQuantity, $newQuantity, $available_stock, $productId);
        
        if (!$updateProductStmt->execute()) {
            throw new Exception("Failed to update product");
        }
        
        // Log the inventory change - using only columns that exist
        $logQuery = "INSERT INTO inventory_logs (product_id, action, quantity) 
                     VALUES (?, 'manual_update', ?)";
        $logStmt = $conn->prepare($logQuery);
        $logStmt->bind_param('ii', $productId, $newQuantity);
        
        if (!$logStmt->execute()) {
            throw new Exception("Failed to log inventory change");
        }
        
        mysqli_commit($conn);
        $_SESSION['success_message'] = "Stock updated successfully! Available: $available_stock units";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error_message'] = "Failed to update stock: " . $e->getMessage();
    }
    
    header("Location: inventory.php" . ($productFilter ? "?product_id=$productFilter" : ""));
    exit();
}

// Handle min stock threshold update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_min_stock'])) {
    $productId = $_POST['product_id'];
    $minStock = intval($_POST['min_stock']);
    
    $updateQuery = "UPDATE inventory SET min_stock = ? WHERE product_id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param('ii', $minStock, $productId);
    
    if ($updateStmt->execute()) {
        $_SESSION['success_message'] = "Minimum stock level updated successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to update minimum stock level.";
    }
    
    header("Location: inventory.php" . ($productFilter ? "?product_id=$productFilter" : ""));
    exit();
}

// Calculate statistics
$totalProducts = count($products);
$lowStockCount = 0;
$outOfStockCount = 0;
$totalReserved = 0;
$totalAvailable = 0;

foreach ($products as $product) {
    $stock = $product['available_stock'] ?? 0;
    $reserved = $product['reserved_quantity'] ?? 0;
    $total_stock = $product['current_stock'] ?? 0;
    
    // Ensure available stock doesn't exceed total stock
    $stock = min($stock, $total_stock);
    
    if ($stock == 0) {
        $outOfStockCount++;
    } elseif ($stock <= $lowStockThreshold) {
        $lowStockCount++;
    }
    
    $totalReserved += $reserved;
    $totalAvailable += $stock;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - KB Riders Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .stock-indicator {
            padding: 8px;
            border-radius: 6px;
            font-weight: 500;
        }
        .stock-critical {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        .stock-low {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        .stock-good {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .reservation-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        .product-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .quantity-input {
            width: 80px;
            text-align: center;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 4px;
        }
        .btn-update {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            transition: opacity 0.2s;
        }
        .btn-update:hover {
            opacity: 0.9;
        }
        .stock-discrepancy-warning {
            color: #dc3545;
            font-size: 0.8rem;
            margin-top: 5px;
            display: block;
        }
    </style>
</head>
<body>
    <!-- Include Admin Navbar -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid py-4">
        <!-- Header Section -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>
                <i class="bi bi-clipboard-data"></i> Inventory Management
            </h1>
            <div class="d-flex gap-2">
                <a href="products.php" class="btn btn-outline-primary">
                    <i class="bi bi-box"></i> Products
                </a>
                <a href="reservations.php" class="btn btn-outline-warning">
                    <i class="bi bi-calendar-check"></i> Reservations
                </a>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Products</h5>
                        <h2><?php echo $totalProducts; ?></h2>
                        <small>Active inventory items</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <h5 class="card-title">Low Stock</h5>
                        <h2><?php echo $lowStockCount; ?></h2>
                        <small>Below <?php echo $lowStockThreshold; ?> units</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h5 class="card-title">Out of Stock</h5>
                        <h2><?php echo $outOfStockCount; ?></h2>
                        <small>Need restock</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Reserved Units</h5>
                        <h2><?php echo $totalReserved; ?></h2>
                        <small>From confirmed reservations</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter and Search Section -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Filter by Product</label>
                        <select name="product_id" class="form-select">
                            <option value="0">All Products</option>
                            <?php
                            mysqli_data_seek($productsResult, 0);
                            while ($product = $productsResult->fetch_assoc()) {
                                $selected = ($productFilter == $product['id']) ? 'selected' : '';
                                echo '<option value="' . $product['id'] . '" ' . $selected . '>' . 
                                     htmlspecialchars($product['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Filter by Brand</label>
                        <select name="brand_id" class="form-select">
                            <option value="0">All Brands</option>
                            <?php
                            mysqli_data_seek($brandsResult, 0);
                            while ($brand = $brandsResult->fetch_assoc()) {
                                $selected = ($brandFilter == $brand['id']) ? 'selected' : '';
                                echo '<option value="' . $brand['id'] . '" ' . $selected . '>' . 
                                     htmlspecialchars($brand['brand_name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search Products</label>
                        <input type="text" 
                               name="search" 
                               class="form-control" 
                               placeholder="Search by name, code..."
                               value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel"></i> Apply Filters
                        </button>
                    </div>
                </form>
                
                <?php if ($brandFilter > 0 || !empty($searchQuery) || $productFilter > 0): ?>
                <div class="mt-3">
                    <a href="inventory.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Inventory Grid -->
        <div class="row">
            <?php if (empty($products)): ?>
                <div class="col-12">
                    <div class="alert alert-info text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                        <h4 class="mt-3">No products found</h4>
                        <p>Try adjusting your filters or add new products</p>
                        <a href="products.php" class="btn btn-primary mt-2">
                            <i class="bi bi-plus-circle"></i> Add Products
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $stock = $product['available_stock'] ?? 0;
                    $total_stock = $product['current_stock'] ?? 0;
                    $reserved = $product['reserved_quantity'] ?? 0;
                    $minStock = $product['min_stock'] ?? $lowStockThreshold;
                    $reservation_info = $reservation_data[$product['id']] ?? null;
                    
                    // Ensure available stock doesn't exceed total stock
                    $stock = min($stock, $total_stock);
                    
                    // Determine stock status
                    if ($stock <= 0) {
                        $stockClass = 'stock-critical';
                        $stockText = 'Out of Stock';
                        $stockIcon = 'bi-x-circle-fill';
                    } elseif ($stock <= $minStock) {
                        $stockClass = 'stock-low';
                        $stockText = 'Low Stock';
                        $stockIcon = 'bi-exclamation-triangle-fill';
                    } else {
                        $stockClass = 'stock-good';
                        $stockText = 'In Stock';
                        $stockIcon = 'bi-check-circle-fill';
                    }
                    ?>
                    
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card product-card h-100 position-relative">
                            <!-- Reservation Badges -->
                            <div class="reservation-badge">
                                <?php if ($reservation_info && ($reservation_info['pending_count'] ?? 0) > 0): ?>
                                <span class="badge bg-warning text-dark mb-1" title="Pending Reservations">
                                    <i class="bi bi-clock-history"></i> <?php echo $reservation_info['pending_count'] ?? 0; ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($reserved > 0): ?>
                                <span class="badge bg-info" title="Reserved Units">
                                    <i class="bi bi-lock"></i> <?php echo $reserved; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-body">
                                <!-- Product Image -->
                                <div class="text-center mb-3">
                                    <?php if (!empty($product['image'])): ?>
                                        <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                             class="product-image" style="max-height: 120px; object-fit: contain;">
                                    <?php else: ?>
                                        <div class="product-image d-flex align-items-center justify-content-center bg-light" 
                                             style="height: 120px; border-radius: 8px;">
                                            <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Product Info -->
                                <h5 class="card-title text-center mb-1">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </h5>
                                <p class="text-center text-muted small mb-2">
                                    <?php echo htmlspecialchars($product['brand_name'] ?? 'No Brand'); ?> | 
                                    Code: <?php echo htmlspecialchars($product['product_code'] ?? 'N/A'); ?>
                                </p>
                                
                                <!-- Stock Status -->
                                <div class="<?php echo $stockClass; ?> stock-indicator mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>
                                            <i class="bi <?php echo $stockIcon; ?> me-2"></i>
                                            <?php echo $stockText; ?>
                                        </span>
                                        <span class="fw-bold"><?php echo $stock; ?> avail.</span>
                                    </div>
                                    <div class="small mt-2">
                                        <div class="d-flex justify-content-between">
                                            <span>Total Stock:</span>
                                            <span class="fw-bold"><?php echo $total_stock; ?></span>
                                        </div>
                                        <?php if ($reserved > 0): ?>
                                        <div class="d-flex justify-content-between text-warning">
                                            <span>Reserved:</span>
                                            <span class="fw-bold"><?php echo $reserved; ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between">
                                            <span>Min Stock Level:</span>
                                            <span class="fw-bold"><?php echo $minStock; ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Product Details -->
                                <div class="mb-3 small">
                                    <?php if (!empty($product['colors'])): ?>
                                    <div class="mb-1">
                                        <i class="bi bi-palette me-1"></i>
                                        Colors: <?php echo htmlspecialchars($product['colors']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($product['sizes'])): ?>
                                    <div class="mb-1">
                                        <i class="bi bi-rulers me-1"></i>
                                        Sizes: <?php echo htmlspecialchars($product['sizes']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Quick Actions -->
                                <div class="mt-3">
                                    <!-- Stock Update Form -->
                                    <form method="POST" class="mb-2">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <div class="input-group">
                                            <span class="input-group-text">Stock</span>
                                            <input type="number" 
                                                   name="quantity" 
                                                   value="<?php echo $total_stock; ?>" 
                                                   min="<?php echo $reserved; ?>"
                                                   class="form-control"
                                                   required>
                                            <button type="submit" name="update_stock" class="btn btn-primary">
                                                <i class="bi bi-arrow-clockwise"></i> Update
                                            </button>
                                        </div>
                                        <?php if ($reserved > 0): ?>
                                        <small class="text-warning">
                                            <i class="bi bi-exclamation-triangle"></i> 
                                            Min value: <?php echo $reserved; ?> (reserved units)
                                        </small>
                                        <?php endif; ?>
                                    </form>
                                    
                                    <!-- Min Stock Update Form -->
                                    <form method="POST" class="mb-2">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <div class="input-group">
                                            <span class="input-group-text">Min Stock</span>
                                            <input type="number" 
                                                   name="min_stock" 
                                                   value="<?php echo $minStock; ?>" 
                                                   min="1"
                                                   class="form-control">
                                            <button type="submit" name="update_min_stock" class="btn btn-outline-secondary">
                                                <i class="bi bi-save"></i> Set
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- Navigation Buttons -->
                                    <div class="d-flex gap-1 mt-2">
                                        <a href="products.php?edit=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary flex-fill"
                                           data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $product['id']; ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <a href="reservations.php?product_id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-outline-warning flex-fill">
                                            <i class="bi bi-calendar-check"></i> Reservations
                                            <?php if ($reservation_info && ($reservation_info['pending_count'] ?? 0) > 0): ?>
                                            <span class="badge bg-danger"><?php echo $reservation_info['pending_count']; ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Recent Inventory Logs -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-clock-history"></i> Recent Inventory Activity
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Product</th>
                                <th>Action</th>
                                <th>Quantity</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_logs)): ?>
                                <?php foreach ($recent_logs as $log): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        if (isset($log['created_at']) && !empty($log['created_at'])) {
                                            echo date('M d, Y h:i A', strtotime($log['created_at']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($log['image'])): ?>
                                            <img src="uploads/<?php echo htmlspecialchars($log['image']); ?>" 
                                                 alt="" style="width: 30px; height: 30px; object-fit: cover; border-radius: 4px; margin-right: 8px;">
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($log['product_name'] ?? 'Unknown Product'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = 'secondary';
                                        $action_text = $log['action'] ?? 'unknown';
                                        
                                        if ($action_text == 'out' || $action_text == 'stock_out' || $action_text == 'reservation_out') {
                                            $badge_class = 'danger';
                                            $action_text = 'Stock Out';
                                        } elseif ($action_text == 'in' || $action_text == 'stock_in' || $action_text == 'reservation_in') {
                                            $badge_class = 'success';
                                            $action_text = 'Stock In';
                                        } elseif ($action_text == 'order') {
                                            $badge_class = 'info';
                                            $action_text = 'Order';
                                        } elseif ($action_text == 'manual_update') {
                                            $badge_class = 'primary';
                                            $action_text = 'Manual Update';
                                        } elseif ($action_text == 'product_add') {
                                            $badge_class = 'success';
                                            $action_text = 'Product Added';
                                        } elseif ($action_text == 'product_update') {
                                            $badge_class = 'info';
                                            $action_text = 'Product Updated';
                                        } elseif ($action_text == 'product_delete') {
                                            $badge_class = 'danger';
                                            $action_text = 'Product Deleted';
                                        } elseif (strpos($action_text, 'reservation_confirm') !== false) {
                                            $badge_class = 'warning';
                                            $action_text = 'Reservation Confirmed';
                                        } elseif (strpos($action_text, 'reservation_cancel') !== false) {
                                            $badge_class = 'secondary';
                                            $action_text = 'Reservation Cancelled';
                                        } else {
                                            $action_text = ucwords(str_replace('_', ' ', $action_text));
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?>">
                                            <?php echo $action_text; ?>
                                        </span>
                                    </td>
                                    <td><?php echo isset($log['quantity']) ? $log['quantity'] : '0'; ?></td>
                                    <td>
                                        <?php 
                                        if (isset($log['reference_id']) && !empty($log['reference_id'])): 
                                        ?>
                                            <?php if (isset($log['action']) && strpos($log['action'], 'reservation') !== false): ?>
                                            <a href="reservations.php?id=<?php echo $log['reference_id']; ?>" class="btn btn-sm btn-outline-info">
                                                <i class="bi bi-calendar-check"></i> View
                                            </a>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">Ref #<?php echo $log['reference_id']; ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                        <p class="mt-2">No inventory logs found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Quantity input validation
    document.querySelectorAll('input[name="quantity"]').forEach(input => {
        input.addEventListener('change', function() {
            const min = parseInt(this.min) || 0;
            const value = parseInt(this.value) || 0;
            if (value < min) {
                this.value = min;
                alert('Stock cannot be less than reserved quantity (' + min + ')');
            }
        });
    });
    
    // Auto-refresh every 60 seconds (optional)
    // setTimeout(() => {
    //     window.location.reload();
    // }, 60000);
    </script>
</body>
</html>