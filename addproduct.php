<?php
session_start();
require_once "config.php";

/* ===============================
   ADD / UPDATE PRODUCT (FIXED)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = $_POST['id'] ?? '';
    $product_code = $_POST['product_code'] ?? '';
    $name  = $_POST['name']  ?? '';
    $price = $_POST['price'] ?? 0;
    $brand = $_POST['brand'] ?? '';
    $color = $_POST['color'] ?? '';
    $description = $_POST['description'] ?? '';
    $specs = $_POST['specs'] ?? '';

    /* IMAGE UPLOAD */
    $imageName = $_POST['old_image'] ?? '';

    if (!empty($_FILES['image']['name'])) {

        // ✅ FILE SIZE LIMIT (5MB)
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            header("Location: addproduct.php?img_error=1");
            exit;
        }

        $imageName = time() . '_' . basename($_FILES['image']['name']);
        $dir = "images/products/";

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        move_uploaded_file($_FILES['image']['tmp_name'], $dir . $imageName);
    }

    /* ===== UPDATE PRODUCT ===== */
    if (!empty($id)) {

        $stmt = $conn->prepare("
            UPDATE products 
            SET product_code=?, name=?, price=?, brand=?, color=?, description=?, specs=?, image=?
            WHERE id=?
        ");
        $stmt->bind_param(
            "ssdsssssi",
            $product_code,
            $name,
            $price,
            $brand,
            $color,
            $description,
            $specs,
            $imageName,
            $id
        );
        $stmt->execute();

        header("Location: addproduct.php?updated=1");
        exit;
    }

    /* ===== ADD PRODUCT (FIXED – NO DUPLICATE) ===== */
    $stmt = $conn->prepare("
        INSERT INTO products (product_code, name, price, brand, color, description, specs, image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "ssdsssss",
        $product_code,
        $name,
        $price,
        $brand,
        $color,
        $description,
        $specs,
        $imageName
    );
    $stmt->execute();

    $product_id = $conn->insert_id;

    /* ===== AUTO CREATE INVENTORY (SAFE) ===== */
    $check = $conn->prepare("SELECT id FROM inventory WHERE product_id=?");
    $check->bind_param("i", $product_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows === 0) {
        $inv = $conn->prepare("
            INSERT INTO inventory (product_id, quantity, min_stock)
            VALUES (?, 0, 5)
        ");
        $inv->bind_param("i", $product_id);
        $inv->execute();
    }

    header("Location: addproduct.php?success=1");
    exit;
}

/* ===== DELETE ===== */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM products WHERE id=$id");
    $conn->query("DELETE FROM inventory WHERE product_id=$id");
    header("Location: addproduct.php");
    exit;
}

/* ===== FETCH FOR EDIT ===== */
$editProduct = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $editProduct = $conn->query("SELECT * FROM products WHERE id=$id")->fetch_assoc();
}

/* ===== FETCH ALL ===== */
$products = $conn->query("SELECT * FROM products ORDER BY id DESC");
?>


<!DOCTYPE html>
<html>
<head>
<title>Product Manager</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

:root {
    --bg: #0a0a0a;
    --bg-gradient: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 100%);
    --panel: #121212;
    --panel-light: #1a1a1a;
    --panel-dark: #0d0d0d;
    --border: #2a2a2a;
    --border-light: #3a3a3a;
    --accent: #ff4757;
    --accent-gradient: linear-gradient(135deg, #ff4757 0%, #ff3838 100%);
    --accent-soft: rgba(255, 71, 87, 0.15);
    --accent-soft-hover: rgba(255, 71, 87, 0.25);
    --text: #f8f9fa;
    --text-muted: #a0a0a0;
    --text-light: #d1d1d1;
    --success: #2ed573;
    --warning: #ffa502;
    --shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    --shadow-heavy: 0 20px 60px rgba(0, 0, 0, 0.7);
    --shadow-accent: 0 10px 30px rgba(255, 71, 87, 0.3);
    --radius-sm: 10px;
    --radius-md: 16px;
    --radius-lg: 24px;
    --radius-xl: 30px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg-gradient);
    color: var(--text);
    min-height: 100vh;
    padding: 30px;
    line-height: 1.6;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
}

/* ===== HEADER ===== */
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 40px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
}

.header-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-title i {
    color: var(--accent);
    font-size: 28px;
}

.header-title h1 {
    font-size: 28px;
    font-weight: 800;
    background: linear-gradient(135deg, #ff4757 0%, #ff9f43 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.header-stats {
    display: flex;
    gap: 15px;
}

.stat {
    background: var(--panel);
    padding: 12px 20px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    text-align: center;
    min-width: 120px;
}

.stat-value {
    font-size: 24px;
    font-weight: 800;
    color: var(--accent);
}

.stat-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 4px;
}

/* ===== MAIN LAYOUT ===== */
.main-content {
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: 40px;
    margin-bottom: 60px;
}

@media (max-width: 1100px) {
    .main-content {
        grid-template-columns: 1fr;
    }
}

/* ===== ADD FORM ===== */
.form-container {
    position: sticky;
    top: 30px;
    height: fit-content;
}

.form-card {
    background: var(--panel);
    border-radius: var(--radius-xl);
    padding: 32px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-heavy);
    position: relative;
    overflow: hidden;
}

.form-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--accent-gradient);
}

.form-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 28px;
}

.form-header i {
    background: var(--accent-soft);
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    font-size: 20px;
}

.form-header h2 {
    font-size: 22px;
    font-weight: 700;
}

.form-group {
    margin-bottom: 22px;
    position: relative;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-light);
}

.form-control {
    width: 100%;
    padding: 16px 20px;
    background: var(--panel-dark);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 15px;
    font-weight: 500;
    transition: var(--transition);
}

.form-control:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}

.form-control::placeholder {
    color: var(--text-muted);
}

select.form-control {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23ff4757' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 20px center;
    background-size: 16px;
    padding-right: 50px;
}

.file-upload {
    position: relative;
    display: block;
}

.file-upload input[type="file"] {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.file-upload-label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 18px;
    background: var(--panel-dark);
    border: 2px dashed var(--border);
    border-radius: var(--radius-md);
    text-align: center;
    color: var(--text-muted);
    transition: var(--transition);
}

.file-upload-label:hover {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent);
}

.file-upload-label i {
    font-size: 20px;
}

.btn-submit {
    width: 100%;
    padding: 18px;
    background: var(--accent-gradient);
    border: none;
    border-radius: var(--radius-md);
    color: white;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.5px;
    cursor: pointer;
    transition: var(--transition);
    margin-top: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.btn-submit:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-accent);
}

.btn-submit:active {
    transform: translateY(0);
}

.btn-back {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 20px;
    padding: 14px;
    background: var(--panel-dark);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text-light);
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: var(--transition);
}

.btn-back:hover {
    background: var(--panel-light);
    border-color: var(--border-light);
    color: white;
}

/* ===== PRODUCTS SECTION ===== */
.products-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.products-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.products-title i {
    color: var(--accent);
    font-size: 24px;
}

.products-title h2 {
    font-size: 24px;
    font-weight: 700;
}

.products-count {
    background: var(--accent-soft);
    color: var(--accent);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 700;
}

/* ===== PRODUCTS GRID ===== */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 26px;
}

/* ===== PRODUCT CARD ===== */
.product-card {
    background: var(--panel);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    overflow: hidden;
    transition: var(--transition);
    box-shadow: var(--shadow);
    position: relative;
}

.product-card:hover {
    transform: translateY(-10px);
    box-shadow: var(--shadow-heavy);
    border-color: var(--accent);
}

.product-badge {
    position: absolute;
    top: 16px;
    right: 16px;
    background: var(--accent);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    z-index: 2;
}

.product-image {
    width: 100%;
    height: 200px;
    object-fit: contain;  /* Changed from 'cover' to 'contain' */
    display: block;
    border-bottom: 1px solid var(--border);
    background-color: var(--panel-dark);  /* Added background for better visibility */
}

.product-content {
    padding: 22px;
}

.product-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.product-price {
    font-size: 24px;
    font-weight: 800;
    color: var(--accent);
    margin-bottom: 12px;
}

.product-brand {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--panel-dark);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-light);
}

.product-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.btn-action {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: var(--transition);
    border: 1px solid transparent;
}

.btn-action-edit {
    background: rgba(32, 59, 124, 0.2);
    color: #7aa7ff;
    border-color: rgba(32, 59, 124, 0.3);
}

.btn-action-edit:hover {
    background: rgba(32, 59, 124, 0.4);
    color: #a3c4ff;
    transform: translateY(-2px);
}

.btn-action-delete {
    background: rgba(255, 71, 87, 0.1);
    color: #ff6b6b;
    border-color: rgba(255, 71, 87, 0.2);
}

.btn-action-delete:hover {
    background: rgba(255, 71, 87, 0.2);
    color: #ff8787;
    transform: translateY(-2px);
}

/* ===== EMPTY STATE ===== */
.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    background: var(--panel);
    border-radius: var(--radius-lg);
    border: 2px dashed var(--border);
}

.empty-icon {
    font-size: 64px;
    color: var(--border);
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 22px;
    margin-bottom: 10px;
    color: var(--text-light);
}

.empty-state p {
    color: var(--text-muted);
    max-width: 400px;
    margin: 0 auto 20px;
}

/* ===== FOOTER ===== */
.footer {
    text-align: center;
    padding-top: 40px;
    margin-top: 40px;
    border-top: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 14px;
}

.footer a {
    color: var(--accent);
    text-decoration: none;
}

.footer a:hover {
    text-decoration: underline;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    body {
        padding: 20px;
    }
    
    .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }
    
    .header-stats {
        width: 100%;
        justify-content: space-between;
    }
    
    .stat {
        flex: 1;
        min-width: auto;
    }
    
    .products-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }
}

@media (max-width: 576px) {
    .products-grid {
        grid-template-columns: 1fr;
    }
    
    .form-card {
        padding: 24px;
    }
}
<?= file_get_contents(__FILE__) && '' ?>

</style>
</head>
<body>
<div class="container">

<?php if(isset($_GET['success'])): ?>
<div style="background:rgba(46,213,115,.15);border:1px solid #2ed573;padding:14px;border-radius:10px;margin-bottom:25px;color:#2ed573;font-weight:600;">
    <i class="fas fa-check-circle"></i> Product added successfully!
</div>
<?php endif; ?>

<?php if(isset($_GET['updated'])): ?>
<div style="background:rgba(255,165,2,.15);border:1px solid #ffa502;padding:14px;border-radius:10px;margin-bottom:25px;color:#ffa502;font-weight:600;">
    <i class="fas fa-sync-alt"></i> Product updated successfully!
</div>
<?php endif; ?>

<?php if(isset($_GET['img_error'])): ?>
<div style="background:rgba(255,71,87,.15);border:1px solid #ff4757;padding:14px;border-radius:10px;margin-bottom:25px;color:#ff4757;font-weight:600;">
    <i class="fas fa-exclamation-circle"></i> Image too large! Max 5MB only.
</div>
<?php endif; ?>


<div class="header">
    <div class="header-title">
        <i class="fas fa-boxes"></i>
        <h1>Product Manager</h1>
    </div>
</div>

<div class="main-content">

<!-- ===== FORM (UI SAME) ===== -->
<div class="form-container">
<div class="form-card">
<div class="form-header">
    <i class="fas fa-plus-circle"></i>
    <h2><?= $editProduct ? 'Edit Product' : 'Add New Product' ?></h2>
</div>

<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="id" value="<?= $editProduct['id'] ?? '' ?>">
<input type="hidden" name="old_image" value="<?= $editProduct['image'] ?? '' ?>">

<div class="form-group">
<label class="form-label">Product Name</label>
<input type="text" name="name" class="form-control" required
value="<?= htmlspecialchars($editProduct['name'] ?? '') ?>">
</div>

<div class="form-group">
<label class="form-label">Price</label>
<input type="number" step="0.01" name="price" class="form-control" required
value="<?= $editProduct['price'] ?? '' ?>">
</div>

<div class="form-group">
<label class="form-label">Brand</label>
<select name="brand" class="form-control" required>
    <?php if(!$editProduct): ?>
        <option value="" disabled selected>Select Brand</option>
    <?php endif; ?>
<?php
$brands = ['sec','kyt','evo','spyder','zebra','gille','hnj'];
foreach ($brands as $b):
?>
<option value="<?= $b ?>" <?= ($editProduct && $editProduct['brand']==$b)?'selected':'' ?>>
<?= strtoupper($b) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<!-- 🔹 NEW FIELDS (NO UI CHANGE STYLE) -->
<div class="form-group">
<label class="form-label">Description</label>
<textarea name="description" class="form-control"><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
</div>

<div class="form-group">
<label class="form-label">Specifications</label>
<textarea name="specs" class="form-control"><?= htmlspecialchars($editProduct['specs'] ?? '') ?></textarea>
</div>

<div class="form-group">
<label class="form-label">Product Code</label>
<input type="text" name="product_code" class="form-control"
value="<?= htmlspecialchars($editProduct['product_code'] ?? '') ?>">
</div>

<div class="form-group">
<label class="form-label">Color</label>
<input type="text" name="color" class="form-control"
value="<?= htmlspecialchars($editProduct['color'] ?? '') ?>">
</div>


<div class="form-group">
<label class="form-label">Product Image</label>
<input type="file" name="image" class="form-control">
</div>

<button type="submit" class="btn-submit">
<i class="fas fa-save"></i>
<?= $editProduct ? 'UPDATE PRODUCT' : 'SAVE PRODUCT' ?>
</button>
</form>

<a href="dashboard.php" class="btn-back">
<i class="fas fa-arrow-left"></i> BACK
</a>
</div>
</div>

<!-- ===== PRODUCTS (UI SAME) ===== -->
<div class="products-section">
<div class="products-grid">
<?php while($p = $products->fetch_assoc()): ?>
<div class="product-card">
<div class="product-badge"><?= strtoupper($p['brand']) ?></div>
<img src="images/products/<?= $p['image'] ?>" class="product-image">

<div class="product-content">
<h3><?= htmlspecialchars($p['name']) ?></h3>
<div class="product-price">₱<?= number_format($p['price'],2) ?></div>

<!-- 🔹 INFO AUTO DISPLAY (NO DESIGN CHANGE) -->
<?php if($p['description']): ?>
<p style="font-size:13px;color:#aaa;margin-top:8px;">
<?= nl2br(htmlspecialchars($p['description'])) ?>
</p>
<?php endif; ?>

<?php if($p['specs']): ?>
<p style="font-size:12px;color:#777;margin-top:6px;">
<?= nl2br(htmlspecialchars($p['specs'])) ?>
</p>
<?php endif; ?>

<div class="product-actions">
<a href="?edit=<?= $p['id'] ?>" class="btn-action btn-action-edit">
<i class="fas fa-edit"></i> Edit
</a>
<a href="?delete=<?= $p['id'] ?>" onclick="return confirm('Delete this product?')" class="btn-action btn-action-delete">
<i class="fas fa-trash"></i> Delete
</a>
</div>
</div>
</div>
<?php endwhile; ?>
</div>
</div>

</div>
</div>
</body>
</html>
