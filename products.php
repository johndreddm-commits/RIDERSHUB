<?php
session_start();
require_once 'config.php';

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
        $image_name = time() . '_' . basename($_FILES['image']['name']);
        $target_file = $target_dir . $image_name;
        move_uploaded_file($_FILES['image']['tmp_name'], $target_file);
    }
    
    // Insert product with helmet_type
    $query = "INSERT INTO products (product_code, name, description, specs, price, brand_id, helmet_type, colors, sizes, image) 
              VALUES ('$product_code', 
                      '" . mysqli_real_escape_string($conn, $_POST['name']) . "',
                      '" . mysqli_real_escape_string($conn, $_POST['description']) . "',
                      '" . mysqli_real_escape_string($conn, $_POST['specs']) . "',
                      " . $_POST['price'] . ",
                      " . $_POST['brand_id'] . ",
                      '" . mysqli_real_escape_string($conn, $_POST['helmet_type']) . "',
                      '" . mysqli_real_escape_string($conn, $_POST['colors']) . "',
                      '" . mysqli_real_escape_string($conn, $_POST['sizes']) . "',
                      '$image_name')";
    
    if(mysqli_query($conn, $query)) {
        $product_id = mysqli_insert_id($conn);
        
        // Add to inventory
        $inv_query = "INSERT INTO inventory (product_id, quantity, min_stock) 
                      VALUES ($product_id, " . $_POST['quantity'] . ", 5)";
        mysqli_query($conn, $inv_query);

        // Update the products table stock and quantity
        $update_product_query = "UPDATE products SET stock = " . $_POST['quantity'] . ", quantity = " . $_POST['quantity'] . " WHERE id = $product_id";
        mysqli_query($conn, $update_product_query);
        
        $_SESSION['message'] = "Product added successfully! SKU: $product_code";
        header("Location: products.php");
        exit();
    }
}

if($action == 'edit' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'];
    
    // Handle image update
    $image_update = '';
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $target_dir = "uploads/";
        $image_name = time() . '_' . basename($_FILES['image']['name']);
        $target_file = $target_dir . $image_name;
        move_uploaded_file($_FILES['image']['tmp_name'], $target_file);
        $image_update = ", image = '$image_name'";
    }
    
    // Update product with helmet_type
    $query = "UPDATE products SET 
              name = '" . mysqli_real_escape_string($conn, $_POST['name']) . "',
              description = '" . mysqli_real_escape_string($conn, $_POST['description']) . "',
              specs = '" . mysqli_real_escape_string($conn, $_POST['specs']) . "',
              price = " . $_POST['price'] . ",
              brand_id = " . $_POST['brand_id'] . ",
              helmet_type = '" . mysqli_real_escape_string($conn, $_POST['helmet_type']) . "',
              colors = '" . mysqli_real_escape_string($conn, $_POST['colors']) . "',
              sizes = '" . mysqli_real_escape_string($conn, $_POST['sizes']) . "'
              $image_update
              WHERE id = $id";
    
    mysqli_query($conn, $query);
    
    // Update inventory
    $inv_query = "UPDATE inventory SET quantity = " . $_POST['quantity'] . " WHERE product_id = $id";
    mysqli_query($conn, $inv_query);
    
    $_SESSION['message'] = "Product updated successfully!";
    header("Location: products.php");
    exit();
}

if($action == 'delete') {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    $query = "UPDATE products SET status = 'inactive' WHERE id = $id";
    mysqli_query($conn, $query);
    $_SESSION['message'] = "Product deleted successfully!";
    header("Location: products.php");
    exit();
}

// Get all products with helmet_type
$query = "SELECT p.*, b.brand_name, i.quantity as inv_quantity 
          FROM products p 
          LEFT JOIN brands b ON p.brand_id = b.id 
          LEFT JOIN inventory i ON p.id = i.product_id 
          WHERE p.status = 'active' 
          ORDER BY p.created_at DESC";
$result = mysqli_query($conn, $query);

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
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">Product Management</h1>
                
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

                <!-- Add Product Button -->
                <div class="d-flex justify-content-between mb-4">
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="bi bi-plus-circle"></i> Add New Product
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#manageBrandsModal">
                            <i class="bi bi-tags"></i> Manage Brands
                        </button>
                    </div>
                </div>

                <!-- Brands Management Card (Collapsible) -->
                <div class="card mb-4 brand-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-tags"></i> Brands Management
                        </h5>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#brandsSection">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                    <div class="collapse show" id="brandsSection">
                        <div class="card-body">
                            <!-- Add Brand Form -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <form method="POST" action="?action=add_brand" class="row g-3">
                                        <div class="col-8">
                                            <input type="text" class="form-control" name="brand_name" 
                                                   placeholder="Enter brand name" required>
                                        </div>
                                        <div class="col-4">
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-plus-lg"></i> Add Brand
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Brands Table -->
                            <div class="table-responsive">
                                <table class="table table-hover brand-table">
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
                                                   onclick="return confirm('Are you sure you want to delete <?php echo addslashes($brand['brand_name']); ?>? <?php echo $brand['product_count'] > 0 ? 'This brand has products associated with it!' : ''; ?>')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Grid -->
                <h4 class="mb-3">Products List</h4>
                <div class="row">
                    <?php while($product = mysqli_fetch_assoc($result)): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card product-card h-100">
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
                                
                                <p class="card-text">
                                    <?php if($product['description']): ?>
                                        <?php echo substr($product['description'], 0, 100); ?>...
                                    <?php endif; ?>
                                </p>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <strong>Price:</strong><br>
                                        <span class="text-success">₱<?php echo number_format($product['price'], 2); ?></span>
                                    </div>
                                    <div class="col-6">
                                        <strong>Stock:</strong><br>
                                        <span class="<?php echo $product['inv_quantity'] <= 5 ? 'text-danger' : 'text-primary'; ?>">
                                            <?php echo $product['inv_quantity']; ?> units
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <strong>Colors:</strong><br>
                                        <small><?php echo $product['colors']; ?></small>
                                    </div>
                                    <div class="col-6">
                                        <strong>Sizes:</strong><br>
                                        <small><?php echo $product['sizes']; ?></small>
                                    </div>
                                </div>
                                
                                <?php if($product['product_code']): ?>
                                <div class="mb-3">
                                    <strong>SKU:</strong><br>
                                    <code><?php echo $product['product_code']; ?></code>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-footer bg-transparent">
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
                                        <h5 class="modal-title">Edit Product</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Product Name</label>
                                                    <input type="text" class="form-control" name="name" 
                                                           value="<?php echo $product['name']; ?>" required>
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
                                                        <option value="full-face" <?php echo (isset($product['helmet_type']) && $product['helmet_type'] == 'full-face') ? 'selected' : ''; ?>>Full Face</option>
                                                        <option value="modular" <?php echo (isset($product['helmet_type']) && $product['helmet_type'] == 'modular') ? 'selected' : ''; ?>>Modular</option>
                                                        <option value="half-face" <?php echo (isset($product['helmet_type']) && $product['helmet_type'] == 'half-face') ? 'selected' : ''; ?>>Half Face</option>
                                                        <option value="open-face" <?php echo (isset($product['helmet_type']) && $product['helmet_type'] == 'open-face') ? 'selected' : ''; ?>>Open Face</option>
                                                        <option value="off-road" <?php echo (isset($product['helmet_type']) && $product['helmet_type'] == 'off-road') ? 'selected' : ''; ?>>Off Road</option>
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
                                                    <label class="form-label">Quantity</label>
                                                    <input type="number" class="form-control" name="quantity" 
                                                           value="<?php echo $product['inv_quantity']; ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Colors (comma-separated)</label>
                                                    <input type="text" class="form-control" name="colors" 
                                                           value="<?php echo $product['colors']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Sizes (comma-separated)</label>
                                                    <input type="text" class="form-control" name="sizes" 
                                                           value="<?php echo $product['sizes']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" rows="3"><?php echo $product['description']; ?></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Specifications</label>
                                            <textarea class="form-control" name="specs" rows="3"><?php echo $product['specs']; ?></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Product Image</label>
                                            <input type="file" class="form-control" name="image" accept="image/*">
                                            <?php if($product['image']): ?>
                                            <small class="text-muted">Current: <?php echo $product['image']; ?></small>
                                            <?php endif; ?>
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
                    <?php endwhile; ?>
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
                        <h5 class="modal-title">Add New Product</h5>
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
                                    <input type="number" class="form-control" name="quantity" required>
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

    <!-- Manage Brands Modal (Alternative simplified view) -->
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
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Brand Name</th>
                                    <th>Products</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                mysqli_data_seek($brands_result, 0);
                                while($brand = mysqli_fetch_assoc($brands_result)): 
                                ?>
                                <tr>
                                    <td><?php echo $brand['brand_name']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $brand['product_count'] > 0 ? 'primary' : 'secondary'; ?>">
                                            <?php echo $brand['product_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?action=delete_brand&id=<?php echo $brand['id']; ?>" 
                                           class="btn btn-sm btn-danger"
                                           onclick="return confirm('Delete <?php echo addslashes($brand['brand_name']); ?>?')">
                                            <i class="bi bi-trash"></i>
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
    <script>
        // Auto-collapse brands section after page load for better UX
        document.addEventListener('DOMContentLoaded', function() {
            // You can optionally collapse the brands section by default
            // var brandsSection = document.getElementById('brandsSection');
            // var bsCollapse = new bootstrap.Collapse(brandsSection, {
            //     toggle: false
            // });
            // bsCollapse.hide();
        });
    </script>
</body>
</html>