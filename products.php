<?php
session_start();
require_once 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Handle form actions
$action = $_GET['action'] ?? '';
$message = '';

// Handle brand actions
if($action == 'add_brand' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $brand_name = mysqli_real_escape_string($conn, $_POST['brand_name']);
    
    // Check if brand already exists
    $check_query = "SELECT * FROM brands WHERE brand_name = '$brand_name'";
    $check_result = mysqli_query($conn, $check_query);
    
    if(mysqli_num_rows($check_result) > 0) {
        $_SESSION['error'] = "Brand already exists!";
    } else {
        $query = "INSERT INTO brands (brand_name) VALUES ('$brand_name')";
        if(mysqli_query($conn, $query)) {
            $_SESSION['message'] = "Brand added successfully!";
        } else {
            $_SESSION['error'] = "Error adding brand!";
        }
    }
    header("Location: products.php");
    exit();
}

if($action == 'delete_brand') {
    $brand_id = mysqli_real_escape_string($conn, $_GET['id']);
    
    // Check if brand is used in products
    $check_query = "SELECT COUNT(*) as product_count FROM products WHERE brand_id = $brand_id";
    $check_result = mysqli_query($conn, $check_query);
    $row = mysqli_fetch_assoc($check_result);
    
    if($row['product_count'] > 0) {
        $_SESSION['error'] = "Cannot delete brand. There are products associated with this brand!";
    } else {
        $query = "DELETE FROM brands WHERE id = $brand_id";
        if(mysqli_query($conn, $query)) {
            $_SESSION['message'] = "Brand deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting brand!";
        }
    }
    header("Location: products.php");
    exit();
}

// Handle product actions with helmet_type integration
if($action == 'add' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    // Generate SKU
    $sku_prefix = strtoupper(substr($_POST['name'], 0, 3));
    $sku_suffix = date('YmdHis');
    $product_code = $sku_prefix . '-' . $sku_suffix;
    
    // Handle image upload
    $image_name = '';
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $target_dir = "uploads/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $image_name = time() . '_' . basename($_FILES['image']['name']);
        $target_file = $target_dir . $image_name;
        move_uploaded_file($_FILES['image']['tmp_name'], $target_file);
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        // Insert product with helmet_type
        $query = "INSERT INTO products (product_code, name, description, specs, price, brand_id, helmet_type, colors, sizes, image, stock, quantity, current_stock, reserved_stock, available_stock, status) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
        
        $stmt = mysqli_prepare($conn, $query);
        $initial_stock = intval($_POST['quantity']);
        $reserved_stock = 0;
        $available_stock = $initial_stock;
        
        mysqli_stmt_bind_param($stmt, "ssssdissssiiiii", 
            $product_code,
            $_POST['name'],
            $_POST['description'],
            $_POST['specs'],
            $_POST['price'],
            $_POST['brand_id'],
            $_POST['helmet_type'],
            $_POST['colors'],
            $_POST['sizes'],
            $image_name,
            $initial_stock,
            $initial_stock,
            $initial_stock,
            $reserved_stock,
            $available_stock
        );
        
        mysqli_stmt_execute($stmt);
        $product_id = mysqli_insert_id($conn);
        
        // Add to inventory
        $inv_query = "INSERT INTO inventory (product_id, quantity, reserved_quantity, min_stock, last_updated) 
                      VALUES (?, ?, ?, 5, NOW())";
        $inv_stmt = mysqli_prepare($conn, $inv_query);
        mysqli_stmt_bind_param($inv_stmt, "iii", $product_id, $initial_stock, $reserved_stock);
        mysqli_stmt_execute($inv_stmt);
        
        // Log the action
        $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, created_at) 
                      VALUES (?, 'product_add', ?, NOW())";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "ii", $product_id, $initial_stock);
        mysqli_stmt_execute($log_stmt);
        
        mysqli_commit($conn);
        
        $_SESSION['message'] = "Product added successfully! SKU: $product_code";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error adding product: " . $e->getMessage();
    }
    
    header("Location: products.php");
    exit();
}

if($action == 'edit' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'];
    
    mysqli_begin_transaction($conn);
    
    try {
        // Handle image update
        $image_update = '';
        $image_name = null;
        if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target_dir = "uploads/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $image_name = time() . '_' . basename($_FILES['image']['name']);
            $target_file = $target_dir . $image_name;
            move_uploaded_file($_FILES['image']['tmp_name'], $target_file);
        }
        
        // Get current reserved stock
        $reserved_query = "SELECT reserved_stock FROM products WHERE id = ?";
        $reserved_stmt = mysqli_prepare($conn, $reserved_query);
        mysqli_stmt_bind_param($reserved_stmt, "i", $id);
        mysqli_stmt_execute($reserved_stmt);
        $reserved_result = mysqli_stmt_get_result($reserved_stmt);
        $reserved_data = mysqli_fetch_assoc($reserved_result);
        $reserved_stock = $reserved_data['reserved_stock'] ?? 0;
        
        $new_quantity = intval($_POST['quantity']);
        $available_stock = $new_quantity - $reserved_stock;
        
        // Update product
        if($image_name) {
            $query = "UPDATE products SET 
                      name = ?,
                      description = ?,
                      specs = ?,
                      price = ?,
                      brand_id = ?,
                      helmet_type = ?,
                      colors = ?,
                      sizes = ?,
                      image = ?,
                      stock = ?,
                      quantity = ?,
                      current_stock = ?,
                      available_stock = ?
                      WHERE id = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "sssdissssiiiii", 
                $_POST['name'],
                $_POST['description'],
                $_POST['specs'],
                $_POST['price'],
                $_POST['brand_id'],
                $_POST['helmet_type'],
                $_POST['colors'],
                $_POST['sizes'],
                $image_name,
                $new_quantity,
                $new_quantity,
                $new_quantity,
                $available_stock,
                $id
            );
        } else {
            $query = "UPDATE products SET 
                      name = ?,
                      description = ?,
                      specs = ?,
                      price = ?,
                      brand_id = ?,
                      helmet_type = ?,
                      colors = ?,
                      sizes = ?,
                      stock = ?,
                      quantity = ?,
                      current_stock = ?,
                      available_stock = ?
                      WHERE id = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "sssdisssiiii", 
                $_POST['name'],
                $_POST['description'],
                $_POST['specs'],
                $_POST['price'],
                $_POST['brand_id'],
                $_POST['helmet_type'],
                $_POST['colors'],
                $_POST['sizes'],
                $new_quantity,
                $new_quantity,
                $new_quantity,
                $available_stock,
                $id
            );
        }
        
        mysqli_stmt_execute($stmt);
        
        // Update inventory
        $inv_query = "UPDATE inventory SET quantity = ?, last_updated = NOW() WHERE product_id = ?";
        $inv_stmt = mysqli_prepare($conn, $inv_query);
        mysqli_stmt_bind_param($inv_stmt, "ii", $new_quantity, $id);
        mysqli_stmt_execute($inv_stmt);
        
        // Log the action
        $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, created_at) 
                      VALUES (?, 'product_update', ?, NOW())";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "ii", $id, $new_quantity);
        mysqli_stmt_execute($log_stmt);
        
        mysqli_commit($conn);
        
        $_SESSION['message'] = "Product updated successfully!";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error updating product: " . $e->getMessage();
    }
    
    header("Location: products.php");
    exit();
}

if($action == 'delete') {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    
    // Check if there are active reservations
    $check_res_query = "SELECT COUNT(*) as count FROM reservations WHERE product_id = ? AND status IN ('PENDING', 'CONFIRMED')";
    $check_res_stmt = mysqli_prepare($conn, $check_res_query);
    mysqli_stmt_bind_param($check_res_stmt, "i", $id);
    mysqli_stmt_execute($check_res_stmt);
    $check_res_result = mysqli_stmt_get_result($check_res_stmt);
    $res_check = mysqli_fetch_assoc($check_res_result);
    
    if($res_check['count'] > 0) {
        $_SESSION['error'] = "Cannot delete product. There are active reservations for this product!";
    } else {
        $query = "UPDATE products SET status = 'inactive' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $_SESSION['message'] = "Product deleted successfully!";
    }
    
    header("Location: products.php");
    exit();
}

// Get filter parameters
$brand_filter = isset($_GET['brand_id']) ? intval($_GET['brand_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

// Build products query with filters
$product_query = "SELECT p.*, b.brand_name, i.quantity as inv_quantity, i.reserved_quantity as inv_reserved 
                  FROM products p 
                  LEFT JOIN brands b ON p.brand_id = b.id 
                  LEFT JOIN inventory i ON p.id = i.product_id 
                  WHERE p.status = ?";

$params = [$status_filter];
$types = "s";

if($brand_filter > 0) {
    $product_query .= " AND p.brand_id = ?";
    $params[] = $brand_filter;
    $types .= "i";
}

if(!empty($search_query)) {
    $product_query .= " AND (p.name LIKE ? OR p.product_code LIKE ? OR p.description LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

$product_query .= " ORDER BY p.created_at DESC";

$stmt = mysqli_prepare($conn, $product_query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Get all products
$products = [];
while($row = mysqli_fetch_assoc($result)) {
    $products[] = $row;
}

// Get reservation counts and stock info for each product
$product_ids = array_column($products, 'id');
$reservation_counts = [];
$reservation_stock = [];

if(!empty($product_ids)) {
    $ids_string = implode(',', $product_ids);
    $res_count_query = "SELECT 
                            product_id, 
                            COUNT(*) as total_reservations,
                            SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                            SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed_count,
                            SUM(CASE WHEN status = 'CONFIRMED' THEN quantity ELSE 0 END) as reserved_units,
                            SUM(CASE WHEN status = 'PENDING' THEN quantity ELSE 0 END) as pending_units
                        FROM reservations 
                        WHERE product_id IN ($ids_string)
                        GROUP BY product_id";
    $res_count_result = mysqli_query($conn, $res_count_query);
    while($count = mysqli_fetch_assoc($res_count_result)) {
        $reservation_counts[$count['product_id']] = $count;
        $reservation_stock[$count['product_id']] = $count['reserved_units'] ?? 0;
    }
}

// Calculate available stock for each product
foreach($products as &$product) {
    $reserved = $reservation_stock[$product['id']] ?? ($product['inv_reserved'] ?? 0);
    $current_stock = $product['inv_quantity'] ?? $product['stock'] ?? 0;
    $product['reserved_stock'] = $reserved;
    $product['available_stock'] = $current_stock - $reserved;
    $product['pending_count'] = $reservation_counts[$product['id']]['pending_count'] ?? 0;
    $product['confirmed_count'] = $reservation_counts[$product['id']]['confirmed_count'] ?? 0;
}

// Get brands for dropdown and management
$brands_query = "SELECT b.*, COUNT(p.id) as product_count 
                 FROM brands b 
                 LEFT JOIN products p ON b.id = p.brand_id AND p.status = 'active'
                 GROUP BY b.id 
                 ORDER BY b.brand_name";
$brands_result = mysqli_query($conn, $brands_query);

// Get all brands for dropdown (without product count)
$brands_dropdown_query = "SELECT * FROM brands ORDER BY brand_name";
$brands_dropdown_result = mysqli_query($conn, $brands_dropdown_query);

// Get statistics
$stats_query = "SELECT 
                   COUNT(*) as total_products,
                   SUM(CASE WHEN current_stock <= 5 THEN 1 ELSE 0 END) as low_stock,
                   SUM(reserved_stock) as total_reserved,
                   SUM(current_stock) as total_stock
                FROM products 
                WHERE status = 'active'";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - KB Riders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .reservation-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        .stock-indicator {
            padding: 5px;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .stock-low { background-color: #fff3cd; color: #856404; }
        .stock-critical { background-color: #f8d7da; color: #721c24; }
        .stock-good { background-color: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header with Stats and Navigation -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>
                        <i class="bi bi-box"></i> Product Management
                    </h1>
                    <div class="d-flex gap-2">
                        <a href="inventory.php" class="btn btn-outline-info">
                            <i class="bi bi-clipboard-data"></i> Inventory
                        </a>
                        <a href="reservations.php" class="btn btn-outline-warning">
                            <i class="bi bi-calendar-check"></i> Reservations
                            <?php
                            $pending_count = 0;
                            foreach($products as $p) {
                                $pending_count += $p['pending_count'];
                            }
                            if($pending_count > 0):
                            ?>
                            <span class="badge bg-danger"><?php echo $pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h5 class="card-title">Total Products</h5>
                                <h2><?php echo $stats['total_products'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h5 class="card-title">Low Stock Items</h5>
                                <h2><?php echo $stats['low_stock'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h5 class="card-title">Reserved Units</h5>
                                <h2><?php echo $stats['total_reserved'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title">Total Stock</h5>
                                <h2><?php echo $stats['total_stock'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Messages -->
                <?php if(isset($_SESSION['message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filters and Actions -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Filter by Brand</label>
                                        <select name="brand_id" class="form-select">
                                            <option value="0">All Brands</option>
                                            <?php 
                                            mysqli_data_seek($brands_dropdown_result, 0);
                                            while($brand = mysqli_fetch_assoc($brands_dropdown_result)): ?>
                                            <option value="<?php echo $brand['id']; ?>" 
                                                <?php echo ($brand_filter == $brand['id']) ? 'selected' : ''; ?>>
                                                <?php echo $brand['brand_name']; ?>
                                            </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Search products..." value="<?php echo htmlspecialchars($search_query); ?>">
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-funnel"></i> Apply Filters
                                        </button>
                                        <a href="products.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-x-circle"></i> Clear
                                        </a>
                                    </div>
                                </form>
                            </div>
                            <div class="col-md-4 text-end">
                                <button type="button" class="btn btn-primary mb-2" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                    <i class="bi bi-plus-circle"></i> Add New Product
                                </button>
                                <button type="button" class="btn btn-outline-primary mb-2" data-bs-toggle="modal" data-bs-target="#manageBrandsModal">
                                    <i class="bi bi-tags"></i> Manage Brands
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Grid -->
                <h4 class="mb-3">
                    Products List 
                    <span class="badge bg-secondary"><?php echo count($products); ?> items</span>
                </h4>
                
                <div class="row">
                    <?php if(empty($products)): ?>
                    <div class="col-12">
                        <div class="alert alert-info text-center">
                            <i class="bi bi-info-circle"></i> No products found.
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php foreach($products as $product): ?>
                    <?php 
                    $stock_class = 'stock-good';
                    $stock_status = 'In Stock';
                    if($product['available_stock'] <= 0) {
                        $stock_class = 'stock-critical';
                        $stock_status = 'Out of Stock';
                    } elseif($product['available_stock'] <= 5) {
                        $stock_class = 'stock-low';
                        $stock_status = 'Low Stock';
                    }
                    ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="card product-card h-100 position-relative">
                            <!-- Reservation Badges -->
                            <div class="reservation-badge">
                                <?php if($product['pending_count'] > 0): ?>
                                <span class="badge bg-warning text-dark mb-1" title="Pending Reservations">
                                    <i class="bi bi-clock-history"></i> <?php echo $product['pending_count']; ?>
                                </span>
                                <?php endif; ?>
                                <?php if($product['confirmed_count'] > 0): ?>
                                <span class="badge bg-success" title="Confirmed Reservations">
                                    <i class="bi bi-check-circle"></i> <?php echo $product['confirmed_count']; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Product Image -->
                            <?php if($product['image']): ?>
                            <img src="uploads/<?php echo $product['image']; ?>" 
                                 class="card-img-top product-img p-3" 
                                 alt="<?php echo $product['name']; ?>">
                            <?php else: ?>
                            <div class="card-img-top product-img d-flex align-items-center justify-content-center bg-light">
                                <i class="bi bi-image" style="font-size: 3rem; color: #ccc;"></i>
                            </div>
                            <?php endif; ?>
                            
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $product['name']; ?></h5>
                                <h6 class="card-subtitle mb-2 text-muted">
                                    <?php echo $product['brand_name'] ?: 'No Brand'; ?>
                                </h6>
                                
                                <!-- Helmet Type Display -->
                                <?php if(isset($product['helmet_type']) && !empty($product['helmet_type'])): ?>
                                <div class="mb-2">
                                    <span class="badge bg-info"><?php echo strtoupper($product['helmet_type']); ?> Helmet</span>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Stock Status -->
                                <div class="stock-indicator <?php echo $stock_class; ?> mb-3">
                                    <div class="d-flex justify-content-between">
                                        <span><i class="bi bi-box"></i> <?php echo $stock_status; ?></span>
                                        <span class="fw-bold"><?php echo $product['available_stock']; ?> avail.</span>
                                    </div>
                                    <?php if($product['reserved_stock'] > 0): ?>
                                    <small class="text-warning d-block">
                                        <i class="bi bi-lock"></i> <?php echo $product['reserved_stock']; ?> reserved
                                    </small>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Price -->
                                <div class="mb-3">
                                    <strong>Price:</strong>
                                    <span class="text-success fs-5">₱<?php echo number_format($product['price'], 2); ?></span>
                                </div>
                                
                                <!-- Colors and Sizes -->
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <strong>Colors:</strong><br>
                                        <small><?php echo $product['colors'] ?: 'N/A'; ?></small>
                                    </div>
                                    <div class="col-6">
                                        <strong>Sizes:</strong><br>
                                        <small><?php echo $product['sizes'] ?: 'N/A'; ?></small>
                                    </div>
                                </div>
                                
                                <!-- SKU -->
                                <?php if($product['product_code']): ?>
                                <div class="mb-3">
                                    <strong>SKU:</strong><br>
                                    <code><?php echo $product['product_code']; ?></code>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Description Preview -->
                                <?php if($product['description']): ?>
                                <p class="card-text">
                                    <small><?php echo substr($product['description'], 0, 100); ?>...</small>
                                </p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-footer bg-transparent">
                                <div class="d-grid gap-2">
                                    <!-- Action Buttons -->
                                    <div class="btn-group w-100">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $product['id']; ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <a href="?action=delete&id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Are you sure you want to delete this product?')">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </div>
                                    
                                    <!-- Reservation Links -->
                                    <div class="btn-group w-100">
                                        <a href="reservations.php?product_id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-calendar-check"></i> View Reservations
                                        </a>
                                        <a href="inventory.php?product_id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-clipboard-data"></i> Stock History
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Product Modal -->
                    <div class="modal fade" id="editProductModal<?php echo $product['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form method="POST" action="?action=edit" enctype="multipart/form-data">
                                    <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">
                                            <i class="bi bi-pencil"></i> Edit Product: <?php echo $product['name']; ?>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Product Name</label>
                                                    <input type="text" class="form-control" name="name" 
                                                           value="<?php echo htmlspecialchars($product['name']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Brand</label>
                                                    <select class="form-select" name="brand_id" required>
                                                        <option value="">Select Brand</option>
                                                        <?php 
                                                        mysqli_data_seek($brands_dropdown_result, 0);
                                                        while($brand = mysqli_fetch_assoc($brands_dropdown_result)): ?>
                                                        <option value="<?php echo $brand['id']; ?>"
                                                            <?php echo $product['brand_id'] == $brand['id'] ? 'selected' : ''; ?>>
                                                            <?php echo $brand['brand_name']; ?>
                                                        </option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Helmet Type</label>
                                                    <select class="form-select" name="helmet_type" required>
                                                        <option value="full-face" <?php echo ($product['helmet_type'] == 'full-face') ? 'selected' : ''; ?>>Full Face</option>
                                                        <option value="modular" <?php echo ($product['helmet_type'] == 'modular') ? 'selected' : ''; ?>>Modular</option>
                                                        <option value="half-face" <?php echo ($product['helmet_type'] == 'half-face') ? 'selected' : ''; ?>>Half Face</option>
                                                        <option value="open-face" <?php echo ($product['helmet_type'] == 'open-face') ? 'selected' : ''; ?>>Open Face</option>
                                                        <option value="off-road" <?php echo ($product['helmet_type'] == 'off-road') ? 'selected' : ''; ?>>Off Road</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Price (₱)</label>
                                                    <input type="number" class="form-control" name="price" 
                                                           step="0.01" value="<?php echo $product['price']; ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Total Stock Quantity</label>
                                                    <input type="number" class="form-control" name="quantity" 
                                                           value="<?php echo $product['inv_quantity'] ?? $product['stock']; ?>" 
                                                           min="0" required>
                                                    <?php if($product['reserved_stock'] > 0): ?>
                                                    <small class="text-warning">
                                                        <i class="bi bi-exclamation-triangle"></i> 
                                                        <?php echo $product['reserved_stock']; ?> units are reserved
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Colors (comma-separated)</label>
                                                    <input type="text" class="form-control" name="colors" 
                                                           value="<?php echo htmlspecialchars($product['colors']); ?>"
                                                           placeholder="e.g., Red, Blue, Black">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Sizes (comma-separated)</label>
                                                    <input type="text" class="form-control" name="sizes" 
                                                           value="<?php echo htmlspecialchars($product['sizes']); ?>"
                                                           placeholder="e.g., S, M, L, XL">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($product['description']); ?></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Specifications</label>
                                            <textarea class="form-control" name="specs" rows="3"><?php echo htmlspecialchars($product['specs']); ?></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Product Image</label>
                                            <input type="file" class="form-control" name="image" accept="image/*">
                                            <?php if($product['image']): ?>
                                            <small class="text-muted">Current: <?php echo $product['image']; ?></small>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Stock Information Summary -->
                                        <div class="alert alert-info">
                                            <h6 class="mb-2">Current Stock Status:</h6>
                                            <div class="row">
                                                <div class="col-4">
                                                    <small>Total: <?php echo $product['inv_quantity'] ?? $product['stock']; ?></small>
                                                </div>
                                                <div class="col-4">
                                                    <small>Reserved: <?php echo $product['reserved_stock']; ?></small>
                                                </div>
                                                <div class="col-4">
                                                    <small>Available: <?php echo $product['available_stock']; ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Update Product</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="?action=add" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-plus-circle"></i> Add New Product
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Product Name</label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Brand</label>
                                    <select class="form-select" name="brand_id" required>
                                        <option value="">Select Brand</option>
                                        <?php 
                                        mysqli_data_seek($brands_dropdown_result, 0);
                                        while($brand = mysqli_fetch_assoc($brands_dropdown_result)): ?>
                                        <option value="<?php echo $brand['id']; ?>">
                                            <?php echo $brand['brand_name']; ?>
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Helmet Type</label>
                                    <select class="form-select" name="helmet_type" required>
                                        <option value="full-face">Full Face</option>
                                        <option value="modular">Modular</option>
                                        <option value="half-face">Half Face</option>
                                        <option value="open-face">Open Face</option>
                                        <option value="off-road">Off Road</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Price (₱)</label>
                                    <input type="number" class="form-control" name="price" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Initial Quantity</label>
                                    <input type="number" class="form-control" name="quantity" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Colors (comma-separated)</label>
                                    <input type="text" class="form-control" name="colors" 
                                           placeholder="e.g., Red, Blue, Black">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Sizes (comma-separated)</label>
                                    <input type="text" class="form-control" name="sizes" 
                                           placeholder="e.g., S, M, L, XL">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Specifications</label>
                            <textarea class="form-control" name="specs" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Product Image</label>
                            <input type="file" class="form-control" name="image" accept="image/*">
                            <small class="text-muted">Recommended size: 500x500 pixels</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manage Brands Modal -->
    <div class="modal fade" id="manageBrandsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-tags"></i> Manage Brands
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Quick add form -->
                    <form method="POST" action="?action=add_brand" class="mb-4">
                        <div class="input-group">
                            <input type="text" class="form-control" name="brand_name" 
                                   placeholder="Enter new brand name" required>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-plus-lg"></i> Add Brand
                            </button>
                        </div>
                    </form>

                    <!-- Brands list -->
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Brand Name</th>
                                    <th>Products Count</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                mysqli_data_seek($brands_result, 0);
                                $brand_counter = 1;
                                while($brand = mysqli_fetch_assoc($brands_result)): 
                                ?>
                                <tr>
                                    <td><?php echo $brand_counter++; ?></td>
                                    <td>
                                        <strong><?php echo $brand['brand_name']; ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $brand['product_count'] > 0 ? 'primary' : 'secondary'; ?>">
                                            <?php echo $brand['product_count']; ?> products
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?action=delete_brand&id=<?php echo $brand['id']; ?>" 
                                           class="btn btn-sm btn-danger"
                                           onclick="return confirm('Are you sure you want to delete <?php echo addslashes($brand['brand_name']); ?>?')">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>