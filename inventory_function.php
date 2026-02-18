<?php
session_start();
require_once "config.php";

// --- 1. ADD PRODUCT LOGIC ---
if(isset($_POST['add_product'])){
    $name     = trim($_POST['name']);
    $brand    = $_POST['brand']; 
    $price    = $_POST['price'];
    // Cast to int to ensure it's a number even if the input is empty
    $quantity = (int)$_POST['quantity']; 
    $colors   = trim($_POST['colors']);   
    $sizes    = trim($_POST['sizes']);    
    
    $target_dir = "uploads/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $image_name = time() . "_" . basename($_FILES["image"]["name"]);
    $target_file = $target_dir . $image_name;

    $check = getimagesize($_FILES["image"]["tmp_name"]);
    if($check !== false) {
        if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
            // Using "i" for quantity (integer) and "d" for price (double/decimal)
            $stmt = $conn->prepare("INSERT INTO products (name, brand, price, quantity, colors, sizes, image) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdisss", $name, $brand, $price, $quantity, $colors, $sizes, $image_name);
            
            if($stmt->execute()){
                header("Location: dashboard.php?view=inventory&status=added");
                exit;
            } else {
                die("Database Error: " . $stmt->error); // Use $stmt->error for more detail
            }
        }
    } else {
        die("File is not an image.");
    }
}

// --- 2. UPDATE (EDIT) PRODUCT LOGIC ---
if(isset($_POST['update_product'])){
    $id       = (int)$_POST['product_id'];
    $name     = trim($_POST['name']);
    $price    = $_POST['price'];
    $quantity = (int)$_POST['quantity']; 
    $colors   = trim($_POST['colors']);   
    $sizes    = trim($_POST['sizes']);    

    if(!empty($_FILES["image"]["name"])){
        $target_dir = "uploads/";
        $image_name = time() . "_" . basename($_FILES["image"]["name"]);
        $target_file = $target_dir . $image_name;

        if(move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)){
            $stmt = $conn->prepare("UPDATE products SET name=?, price=?, quantity=?, colors=?, sizes=?, image=? WHERE id=?");
            $stmt->bind_param("sdisssi", $name, $price, $quantity, $colors, $sizes, $image_name, $id);
        }
    } else {
        $stmt = $conn->prepare("UPDATE products SET name=?, price=?, quantity=?, colors=?, sizes=? WHERE id=?");
        $stmt->bind_param("sdissi", $name, $price, $quantity, $colors, $sizes, $id);
    }

    if($stmt->execute()){
        header("Location: dashboard.php?view=inventory&status=updated");
        exit;
    } else {
        die("Update Error: " . $stmt->error);
    }
}

// --- 3. DELETE PRODUCT LOGIC ---
if(isset($_GET['delete'])){
    $id = (int)$_GET['delete'];
    
    $result = $conn->query("SELECT image FROM products WHERE id = $id");
    if($row = $result->fetch_assoc()){
        if(!empty($row['image']) && file_exists("uploads/" . $row['image'])) {
            unlink("uploads/" . $row['image']); 
        }
    }

    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if($stmt->execute()){
        header("Location: dashboard.php?view=inventory&status=deleted");
        exit;
    }
}
// --- 4. ADD BRAND LOGIC ---
if (isset($_POST['add_brand'])) {
    $brand_name = mysqli_real_escape_string($conn, trim($_POST['brand_name']));
    
    // Check if brand already exists
    $check = $conn->query("SELECT id FROM brands WHERE brand_name = '$brand_name'");
    
    if ($check->num_rows > 0) {
        $_SESSION['msg_error'] = "Brand '$brand_name' already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO brands (brand_name) VALUES (?)");
        $stmt->bind_param("s", $brand_name);
        
        if ($stmt->execute()) {
            $_SESSION['msg_success'] = "Brand added successfully!";
        } else {
            $_SESSION['msg_error'] = "Database Error: " . $conn->error;
        }
    }
    header("Location: dashboard.php?view=inventory");
    exit();
}

// --- 5. DELETE BRAND LOGIC ---
if (isset($_GET['delete_brand'])) {
    $id = (int)$_GET['delete_brand'];
    
    // Optional: Check if products are currently using this brand before deleting
    $stmt = $conn->prepare("DELETE FROM brands WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['msg_success'] = "Brand deleted!";
    } else {
        $_SESSION['msg_error'] = "Error deleting brand.";
    }
    header("Location: dashboard.php?view=inventory");
    exit();
}
?>