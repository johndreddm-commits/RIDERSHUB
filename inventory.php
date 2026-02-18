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
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$lowStockThreshold = 5;

// Get all brands for filter dropdown from brands table
$brandsQuery = "SELECT id, brand_name FROM brands ORDER BY brand_name";
$brandsResult = $conn->query($brandsQuery);

// Build the main query with filters
$sql = "SELECT p.*, 
               b.brand_name,
               COALESCE(i.quantity, p.stock, p.quantity) as current_stock,
               COALESCE(i.min_stock, 5) as min_stock
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

$sql .= " ORDER BY b.brand_name, p.name";

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
    // Fallback if prepare fails
    $result = $conn->query($sql);
    $products = $result->fetch_all(MYSQLI_ASSOC);
}

// Handle stock update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $productId = $_POST['product_id'];
    $newQuantity = $_POST['quantity'];
    
    // Check if inventory record exists
    $checkQuery = "SELECT id FROM inventory WHERE product_id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param('i', $productId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        // Update existing
        $updateQuery = "UPDATE inventory SET quantity = ? WHERE product_id = ?";
    } else {
        // Insert new
        $updateQuery = "INSERT INTO inventory (product_id, quantity, min_stock) VALUES (?, ?, ?)";
    }
    
    $updateStmt = $conn->prepare($updateQuery);
    if ($checkResult->num_rows > 0) {
        $updateStmt->bind_param('ii', $newQuantity, $productId);
    } else {
        $defaultMinStock = 5;
        $updateStmt->bind_param('iii', $productId, $newQuantity, $defaultMinStock);
    }
    
    if ($updateStmt->execute()) {
        // Also update the products table stock field for consistency
        $updateProductQuery = "UPDATE products SET stock = ?, quantity = ? WHERE id = ?";
        $updateProductStmt = $conn->prepare($updateProductQuery);
        $updateProductStmt->bind_param('iii', $newQuantity, $newQuantity, $productId);
        $updateProductStmt->execute();
        
        // Log the inventory change
        $logQuery = "INSERT INTO inventory_logs (product_id, action, quantity) VALUES (?, 'update', ?)";
        $logStmt = $conn->prepare($logQuery);
        $logStmt->bind_param('ii', $productId, $newQuantity);
        $logStmt->execute();
        
        $_SESSION['success_message'] = "Stock updated successfully!";
        header("Location: inventory.php");
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to update stock.";
    }
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
    
</head>
<body>
    <!-- Include Admin Navbar -->
    <?php include 'includes/navbar.php'; ?>

   
    <div class="container-fluid py-4">
        <!-- Header Section -->
        <div class="inventory-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1>Inventory Management
                    </h1>
                    
                </div>
                <div class="col-md-6 text-end">
                    <!-- Statistics Summary -->
                    <div class="row">
                        <?php
                        // Calculate statistics
                        $totalProducts = count($products);
                        $lowStockCount = 0;
                        $outOfStockCount = 0;
                        
                        foreach ($products as $product) {
                            $stock = $product['current_stock'] ?? 0;
                            $minStock = $product['min_stock'] ?? $lowStockThreshold;
                            
                            if ($stock == 0) {
                                $outOfStockCount++;
                            } elseif ($stock <= $minStock) {
                                $lowStockCount++;
                            }
                        }
                        ?>
                        <div class="col-4">
                            <div class="card stats-card py-3">
                                <div class="text-center">
                                    <h3 class="mb-0"><?php echo $totalProducts; ?></h3>
                                    <small class="text-muted">Total Products</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="card stats-card py-3">
                                <div class="text-center">
                                    <h3 class="mb-0 text-warning"><?php echo $lowStockCount; ?></h3>
                                    <small class="text-warning">Low Stock</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="card stats-card py-3">
                                <div class="text-center">
                                    <h3 class="mb-0 text-danger"><?php echo $outOfStockCount; ?></h3>
                                    <small class="text-danger">Out of Stock</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter and Search Section -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Filter by Brand</label>
                        <select name="brand_id" class="form-select">
                            <option value="0">All Brands</option>
                            <?php
                            if ($brandsResult->num_rows > 0) {
                                while ($brand = $brandsResult->fetch_assoc()) {
                                    $selected = ($brandFilter == $brand['id']) ? 'selected' : '';
                                    echo '<option value="' . $brand['id'] . '" ' . $selected . '>' . 
                                         htmlspecialchars($brand['brand_name']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Search Products</label>
                        <input type="text" 
                               name="search" 
                               class="form-control search-box" 
                               placeholder="Search by name, code, or description..."
                               value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-danger w-100 filter-btn">
                            <i class="bi bi-funnel me-1"></i> Apply Filters
                        </button>
                    </div>
                </form>
                
                <?php if ($brandFilter > 0 || !empty($searchQuery)): ?>
                <div class="mt-3">
                    <a href="inventory.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Inventory Grid -->
        <div class="row">
            <?php if (empty($products)): ?>
                <div class="col-12">
                    <div class="card no-products">
                        <i class="bi bi-inbox"></i>
                        <h4>No products found</h4>
                        <p>Try adjusting your filters or add new products</p>
                        <a href="products.php" class="btn btn-danger mt-2">
                            <i class="bi bi-plus-circle me-1"></i> Manage Products
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $stock = $product['current_stock'] ?? 0;
                    $minStock = $product['min_stock'] ?? $lowStockThreshold;
                    
                    // Determine stock status
                    if ($stock == 0) {
                        $stockClass = 'stock-out';
                        $stockText = 'Out of Stock';
                        $stockIcon = 'bi-x-circle';
                    } elseif ($stock <= $minStock) {
                        $stockClass = 'stock-low';
                        $stockText = 'Low Stock';
                        $stockIcon = 'bi-exclamation-triangle';
                    } else {
                        $stockClass = 'stock-high';
                        $stockText = 'In Stock';
                        $stockIcon = 'bi-check-circle';
                    }
                    ?>
                    
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <!-- Product Image -->
                                <div class="text-center mb-3">
                                    <?php if (!empty($product['image'])): ?>
                                        <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                             class="product-image">
                                    <?php else: ?>
                                        <div class="product-image d-flex align-items-center justify-content-center bg-light">
                                            <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Product Info -->
                                <h5 class="card-title">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </h5>
                                
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="brand-badge">
                                        <?php echo htmlspecialchars($product['brand_name'] ?? 'No Brand'); ?>
                                    </span>
                                    <span class="text-success fw-bold">
                                        ₱<?php echo number_format($product['price'], 2); ?>
                                    </span>
                                </div>
                                
                                <!-- Stock Status -->
                                <div class="<?php echo $stockClass; ?> stock-indicator mb-3">
                                    <i class="bi <?php echo $stockIcon; ?> me-2"></i>
                                    <?php echo $stockText; ?> (<?php echo $stock; ?> units)
                                </div>
                                
                                <!-- Product Details -->
                                <div class="mb-3">
                                    <small class="text-muted d-block">
                                        <i class="bi bi-tag me-1"></i>
                                        Code: <?php echo htmlspecialchars($product['product_code'] ?? 'N/A'); ?>
                                    </small>
                                    <small class="text-muted d-block">
                                        <i class="bi bi-palette me-1"></i>
                                        Colors: <?php echo htmlspecialchars($product['colors'] ?? 'N/A'); ?>
                                    </small>
                                    <small class="text-muted d-block">
                                        <i class="bi bi-rulers me-1"></i>
                                        Sizes: <?php echo htmlspecialchars($product['sizes'] ?? 'N/A'); ?>
                                    </small>
                                </div>
                                
                                <!-- Quick Actions -->
                                <div class="d-flex justify-content-between align-items-center">
                                    <button class="btn btn-sm btn-outline-info" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#productModal<?php echo $product['id']; ?>">
                                        <i class="bi bi-eye me-1"></i> View Details
                                    </button>
                                    
                                    <!-- Stock Update Form -->
                                    <form method="POST" class="d-flex align-items-center" 
                                          onsubmit="return confirm('Update stock for <?php echo htmlspecialchars(addslashes($product['name'])); ?>?')">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <input type="number" 
                                               name="quantity" 
                                               value="<?php echo $stock; ?>" 
                                               min="0"
                                               class="quantity-input me-2"
                                               required>
                                        <button type="submit" name="update_stock" class="btn-update btn-sm">
                                            <i class="bi bi-arrow-clockwise me-1"></i> Update
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Product Details Modal -->
                    <div class="modal fade" id="productModal<?php echo $product['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Product Details</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <?php if (!empty($product['image'])): ?>
                                                <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" 
                                                     alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                                     class="img-fluid rounded">
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                                            <p><strong>Brand:</strong> <?php echo htmlspecialchars($product['brand_name'] ?? 'N/A'); ?></p>
                                            <p><strong>Price:</strong> ₱<?php echo number_format($product['price'], 2); ?></p>
                                            <p><strong>Current Stock:</strong> 
                                                <span class="badge <?php echo $stockClass; ?>">
                                                    <?php echo $stock; ?> units
                                                </span>
                                            </p>
                                            <p><strong>Min Stock Level:</strong> <?php echo $minStock; ?> units</p>
                                            <p><strong>Colors:</strong> <?php echo htmlspecialchars($product['colors'] ?? 'N/A'); ?></p>
                                            <p><strong>Sizes:</strong> <?php echo htmlspecialchars($product['sizes'] ?? 'N/A'); ?></p>
                                            
                                            <?php if (!empty($product['description'])): ?>
                                                <hr>
                                                <h6>Description:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($product['specs'])): ?>
                                                <h6>Specifications:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($product['specs'])); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                              
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Pagination (if needed) -->
        <?php if (!empty($products) && count($products) > 12): ?>
        <nav aria-label="Inventory pagination">
            <ul class="pagination justify-content-center">
                <li class="page-item disabled">
                    <a class="page-link" href="#" tabindex="-1">Previous</a>
                </li>
                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                <li class="page-item"><a class="page-link" href="#">2</a></li>
                <li class="page-item"><a class="page-link" href="#">3</a></li>
                <li class="page-item">
                    <a class="page-link" href="#">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Show success/error messages
    <?php if (isset($_SESSION['success_message'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: '<?php echo $_SESSION['success_message']; ?>',
            background: '#1e1e1e',
            color: '#fff',
            confirmButtonColor: '#dc3545'
        });
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: '<?php echo $_SESSION['error_message']; ?>',
            background: '#1e1e1e',
            color: '#fff',
            confirmButtonColor: '#dc3545'
        });
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    
    // Quick stock update validation
    document.querySelectorAll('.quantity-input').forEach(input => {
        input.addEventListener('change', function() {
            if (this.value < 0) {
                this.value = 0;
            }
        });
    });
    
    // Auto-refresh inventory every 60 seconds
    setTimeout(() => {
        window.location.reload();
    }, 60000);
    </script>
</body>
</html>