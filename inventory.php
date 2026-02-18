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
$lowStockThreshold = 5;

// Get all brands for filter dropdown
$brandsQuery = "SELECT id, brand_name FROM brands ORDER BY brand_name";
$brandsResult = $conn->query($brandsQuery);

// Get all products for filter dropdown
$productsQuery = "SELECT id, name, product_code FROM products WHERE status = 'active' ORDER BY name";
$productsResult = $conn->query($productsQuery);

// ✅ FIXED: Physical stock = available stock (after confirmations)
$sql = "SELECT p.*, 
               b.brand_name,
               COALESCE(i.quantity, 0) as stock,
               COALESCE(i.min_stock, 5) as min_stock,
               i.last_updated as inventory_last_updated
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
                WHEN COALESCE(i.quantity, 0) <= 0 THEN 1
                WHEN COALESCE(i.quantity, 0) <= ? THEN 2
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

// Get reservation counts for info only (optional)
$product_ids = array_column($products, 'id');
$reservation_data = [];

if (!empty($product_ids)) {
    $ids_string = implode(',', $product_ids);
    $res_query = "SELECT 
                    product_id, 
                    COUNT(*) as total_reservations,
                    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed_count
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

// Get inventory logs
$logs_query = "SELECT l.*, p.name as product_name, p.image 
               FROM inventory_logs l 
               JOIN products p ON l.product_id = p.id 
               ORDER BY l.id DESC 
               LIMIT 20";
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
        // Check if inventory record exists
        $checkQuery = "SELECT id FROM inventory WHERE product_id = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param('i', $productId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing inventory
            $updateQuery = "UPDATE inventory 
                           SET quantity = ?, 
                               last_updated = NOW() 
                           WHERE product_id = ?";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param('ii', $newQuantity, $productId);
        } else {
            // Insert new inventory record
            $updateQuery = "INSERT INTO inventory (product_id, quantity, min_stock, last_updated) 
                            VALUES (?, ?, 5, NOW())";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param('ii', $productId, $newQuantity);
        }
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update inventory");
        }
        
        // Update products table
        $updateProductQuery = "UPDATE products SET 
                               stock = ?, 
                               quantity = ?, 
                               current_stock = ?,
                               updated_at = NOW()
                               WHERE id = ?";
        $updateProductStmt = $conn->prepare($updateProductQuery);
        $updateProductStmt->bind_param('iiii', $newQuantity, $newQuantity, $newQuantity, $productId);
        
        if (!$updateProductStmt->execute()) {
            throw new Exception("Failed to update product");
        }
        
        // Log the inventory change
        $logQuery = "INSERT INTO inventory_logs (product_id, action, quantity, created_at) 
                     VALUES (?, 'manual_update', ?, NOW())";
        $logStmt = $conn->prepare($logQuery);
        $logStmt->bind_param('ii', $productId, $newQuantity);
        
        if (!$logStmt->execute()) {
            throw new Exception("Failed to log inventory change");
        }
        
        mysqli_commit($conn);
        $_SESSION['success_message'] = "Stock updated successfully! New stock: $newQuantity units";
        
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
$totalStock = 0;

foreach ($products as $product) {
    $stock = $product['stock'] ?? 0;
    
    if ($stock == 0) {
        $outOfStockCount++;
    } elseif ($stock <= $lowStockThreshold) {
        $lowStockCount++;
    }
    
    $totalStock += $stock;
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
            position: relative;
        }
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .stock-value {
            font-size: 1.2rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-clipboard-data"></i> Inventory Management</h1>
            <div class="d-flex gap-2">
                <a href="products.php" class="btn btn-outline-primary"><i class="bi bi-box"></i> Products</a>
                <a href="reservations.php" class="btn btn-outline-warning"><i class="bi bi-calendar-check"></i> Reservations</a>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Products</h5>
                        <h2><?php echo $totalProducts; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <h5 class="card-title">Low Stock</h5>
                        <h2><?php echo $lowStockCount; ?></h2>
                        <small>≤ <?php echo $lowStockThreshold; ?> units</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h5 class="card-title">Out of Stock</h5>
                        <h2><?php echo $outOfStockCount; ?></h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
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
                        <input type="text" name="search" class="form-control" 
                               placeholder="Search by name, code..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply Filters</button>
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
        
        <!-- Inventory Grid - Shows stock only (no sold display) -->
        <div class="row">
            <?php if (empty($products)): ?>
                <div class="col-12">
                    <div class="alert alert-info text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                        <h4 class="mt-3">No products found</h4>
                        <a href="products.php" class="btn btn-primary mt-2"><i class="bi bi-plus-circle"></i> Add Products</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $stock = $product['stock'] ?? 0;
                    $minStock = $product['min_stock'] ?? $lowStockThreshold;
                    $pending_count = $reservation_data[$product['id']]['pending_count'] ?? 0;
                    
                    // Determine stock status
                    if ($stock <= 0) {
                        $stockClass = 'stock-critical';
                        $stockText = 'OUT OF STOCK';
                        $stockIcon = 'bi-x-circle-fill';
                    } elseif ($stock <= $minStock) {
                        $stockClass = 'stock-low';
                        $stockText = 'LOW STOCK';
                        $stockIcon = 'bi-exclamation-triangle-fill';
                    } else {
                        $stockClass = 'stock-good';
                        $stockText = 'IN STOCK';
                        $stockIcon = 'bi-check-circle-fill';
                    }
                    ?>
                    
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card product-card h-100">
                            <!-- Pending Badge (optional) -->
                            <?php if ($pending_count > 0): ?>
                            <div class="reservation-badge">
                                <span class="badge bg-warning text-dark" title="Pending Reservations">
                                    <i class="bi bi-clock-history"></i> <?php echo $pending_count; ?> pending
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="card-body">
                                <!-- Product Image -->
                                <div class="text-center mb-3">
                                    <?php if (!empty($product['image'])): ?>
                                        <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                             style="max-height: 120px; object-fit: contain;">
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center bg-light" 
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
                                        <span class="stock-value"><?php echo $stock; ?> units</span>
                                    </div>
                                </div>
                                
                                <!-- Min Stock -->
                                <div class="text-center small text-muted mb-3">
                                    Min Stock Level: <?php echo $minStock; ?>
                                </div>
                                
                                <!-- Quick Actions -->
                                <div class="mt-3">
                                    <!-- Stock Update Form -->
                                    <form method="POST" class="mb-2">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <div class="input-group">
                                            <span class="input-group-text">Stock</span>
                                            <input type="number" name="quantity" value="<?php echo $stock; ?>" 
                                                   min="0" class="form-control" required>
                                            <button type="submit" name="update_stock" class="btn btn-primary">
                                                <i class="bi bi-arrow-clockwise"></i> Update
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- Min Stock Update Form -->
                                    <form method="POST" class="mb-2">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <div class="input-group">
                                            <span class="input-group-text">Min Stock</span>
                                            <input type="number" name="min_stock" value="<?php echo $minStock; ?>" 
                                                   min="1" class="form-control">
                                            <button type="submit" name="update_min_stock" class="btn btn-outline-secondary">
                                                <i class="bi bi-save"></i> Set
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- Navigation Buttons -->
                                    <div class="d-flex gap-1 mt-2">
                                        <a href="products.php?edit=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary flex-fill">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <a href="reservations.php?product_id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-outline-warning flex-fill">
                                            <i class="bi bi-calendar-check"></i> Reservations
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
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Inventory Activity</h5>
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
                                    <td><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($log['image'])): ?>
                                            <img src="uploads/<?php echo htmlspecialchars($log['image']); ?>" 
                                                 alt="" style="width: 30px; height: 30px; object-fit: cover; border-radius: 4px; margin-right: 8px;">
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($log['product_name'] ?? 'Unknown'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = 'secondary';
                                        $action_text = $log['action'] ?? 'unknown';
                                        
                                        if ($action_text == 'confirmed_sold') {
                                            $badge_class = 'success';
                                            $action_text = 'Confirmed';
                                        } elseif ($action_text == 'manual_update') {
                                            $badge_class = 'primary';
                                            $action_text = 'Manual Update';
                                        } elseif ($action_text == 'cancelled_confirmed') {
                                            $badge_class = 'warning';
                                            $action_text = 'Cancelled';
                                        } elseif ($action_text == 'cancelled_pending') {
                                            $badge_class = 'secondary';
                                            $action_text = 'Cancelled';
                                        } else {
                                            $action_text = ucwords(str_replace('_', ' ', $action_text));
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $action_text; ?></span>
                                    </td>
                                    <td><?php echo $log['quantity']; ?></td>
                                    <td>
                                        <?php if (isset($log['reference_id']) && !empty($log['reference_id'])): ?>
                                            <a href="reservations.php?id=<?php echo $log['reference_id']; ?>" class="btn btn-sm btn-outline-info">View</a>
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
            const value = parseInt(this.value) || 0;
            if (value < 0) this.value = 0;
        });
    });
    </script>
</body>
</html>