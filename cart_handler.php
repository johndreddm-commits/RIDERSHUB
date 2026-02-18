<?php
session_start();
require_once "config.php";

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

switch($action) {
    case 'update_quantity':
        $cart_key = $_POST['cart_key'] ?? '';
        $change = (int)($_POST['change'] ?? 0);
        
        if(isset($_SESSION['cart'][$cart_key])) {
            $new_quantity = $_SESSION['cart'][$cart_key]['quantity'] + $change;
            
            if($new_quantity > 0) {
                $_SESSION['cart'][$cart_key]['quantity'] = $new_quantity;
            } else {
                unset($_SESSION['cart'][$cart_key]);
            }
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Item not found']);
        }
        break;
        
    case 'remove_item':
        $cart_key = $_POST['cart_key'] ?? '';
        
        if(isset($_SESSION['cart'][$cart_key])) {
            unset($_SESSION['cart'][$cart_key]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Item not found']);
        }
        break;
        
    case 'add_to_cart':
        $product_id = (int)($_POST['product_id'] ?? 0);
        $color = $_POST['color'] ?? '';
        $size = $_POST['size'] ?? '';
        $quantity = (int)($_POST['quantity'] ?? 1);
        
        $query = "SELECT id, name, price, image, stock FROM products WHERE id = ? AND status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($result);
        
        if($product) {
            $found = false;
            if(isset($_SESSION['cart'])) {
                foreach($_SESSION['cart'] as $key => $item) {
                    if($item['product_id'] == $product_id && $item['color'] == $color && $item['size'] == $size) {
                        $_SESSION['cart'][$key]['quantity'] += $quantity;
                        $found = true;
                        break;
                    }
                }
            }
            
            if(!$found) {
                $cart_item = [
                    'product_id' => $product_id,
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'image' => $product['image'],
                    'color' => $color,
                    'size' => $size,
                    'quantity' => $quantity,
                    'stock' => $product['stock']
                ];
                $_SESSION['cart'][] = $cart_item;
            }
            
            $cart_count = 0;
            foreach($_SESSION['cart'] as $item) {
                $cart_count += $item['quantity'];
            }
            
            echo json_encode(['success' => true, 'cart_count' => $cart_count]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
        }
        break;
        
    case 'get_product_details':
        $product_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
            exit;
        }
        
        $query = "SELECT colors, sizes, stock, price, name FROM products WHERE id = ? AND status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if($row = mysqli_fetch_assoc($result)) {
            echo json_encode([
                'success' => true,
                'colors' => $row['colors'] ?? 'Standard',
                'sizes' => $row['sizes'] ?? 'One Size',
                'stock' => (int)($row['stock'] ?? 0),
                'price' => (float)$row['price'],
                'name' => $row['name']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
        }
        break;
        
    case 'reserve_from_cart':
        if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
            echo json_encode(['success' => false, 'message' => 'Please login first']);
            exit;
        }
        
        $product_id = (int)($_POST['product_id'] ?? 0);
        $cart_key = $_POST['cart_key'] ?? '';
        $selected_color = $_POST['selected_color'] ?? $_POST['color'] ?? '';
        $selected_size = $_POST['selected_size'] ?? $_POST['size'] ?? '';
        $quantity = (int)($_POST['quantity'] ?? 1);
        $customer_name = $_POST['customer_name'] ?? $_SESSION['fullname'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $pickup_date = $_POST['pickup_date'] ?? date('Y-m-d', strtotime('+1 day'));
        $user_id = $_SESSION['id'] ?? 0;
        
        // Validate inputs
        if($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
            exit;
        }
        
        if($quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid quantity']);
            exit;
        }
        
        if(empty($selected_color)) {
            echo json_encode(['success' => false, 'message' => 'Color is required']);
            exit;
        }
        
        if(empty($selected_size)) {
            echo json_encode(['success' => false, 'message' => 'Size is required']);
            exit;
        }
        
        if(empty($customer_name)) {
            echo json_encode(['success' => false, 'message' => 'Customer name is required']);
            exit;
        }
        
        if(empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Contact number is required']);
            exit;
        }
        
        if(strlen($phone) != 11) {
            echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits']);
            exit;
        }
        
        if(empty($pickup_date)) {
            echo json_encode(['success' => false, 'message' => 'Pickup date is required']);
            exit;
        }
        
        $today = date('Y-m-d');
        if($pickup_date < $today) {
            echo json_encode(['success' => false, 'message' => 'Pickup date cannot be in the past']);
            exit;
        }
        
        if($user_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'User not found. Please login again.']);
            exit;
        }
        
        // Get product details
        $query = "SELECT * FROM products WHERE id = ? AND status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($result);
        
        if(!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found or inactive']);
            exit;
        }
        
        if($product['stock'] < $quantity) {
            echo json_encode(['success' => false, 'message' => 'Insufficient stock. Available: ' . $product['stock']]);
            exit;
        }
        
        // ✅ GENERATE UNIQUE TICKET NUMBER PARA SA BAWAT ITEM
        $ticket_number = 'RES-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        
        mysqli_begin_transaction($conn);
        
        try {
            $query = "INSERT INTO reservations 
                      (user_id, product_id, customer_name, phone, selected_color, selected_size, quantity, pickup_date, ticket_number, status, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "iissssiss", 
                $user_id, 
                $product_id, 
                $customer_name, 
                $phone, 
                $selected_color, 
                $selected_size, 
                $quantity, 
                $pickup_date, 
                $ticket_number
            );
            
            if(!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to create reservation: ' . mysqli_error($conn));
            }
            
            $new_stock = $product['stock'] - $quantity;
            $query = "UPDATE products SET stock = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ii", $new_stock, $product_id);
            
            if(!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to update stock');
            }
            
            // Remove item from cart session
            if(isset($_SESSION['cart'][$cart_key])) {
                unset($_SESSION['cart'][$cart_key]);
            }
            
            mysqli_commit($conn);
            
            echo json_encode([
                'success' => true, 
                'ticket' => $ticket_number,
                'message' => 'Reservation successful'
            ]);
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'message' => 'Reservation failed: ' . $e->getMessage()]);
        }
        break;
        
    // ✅ NEW: RESERVE ALL ITEMS ACTION - BAWAT ITEM MAY SARILING TICKET
    case 'reserve_all_items':
        if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
            echo json_encode(['success' => false, 'message' => 'Please login first']);
            exit;
        }
        
        $cart_items = $_SESSION['cart'] ?? [];
        $customer_name = $_POST['customer_name'] ?? $_SESSION['fullname'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $pickup_date = $_POST['pickup_date'] ?? date('Y-m-d', strtotime('+1 day'));
        $user_id = $_SESSION['id'] ?? 0;
        
        // Validate
        if(empty($cart_items)) {
            echo json_encode(['success' => false, 'message' => 'Cart is empty']);
            exit;
        }
        
        if(empty($customer_name)) {
            echo json_encode(['success' => false, 'message' => 'Customer name is required']);
            exit;
        }
        
        if(empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Contact number is required']);
            exit;
        }
        
        if(strlen($phone) != 11) {
            echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits']);
            exit;
        }
        
        if(empty($pickup_date)) {
            echo json_encode(['success' => false, 'message' => 'Pickup date is required']);
            exit;
        }
        
        $today = date('Y-m-d');
        if($pickup_date < $today) {
            echo json_encode(['success' => false, 'message' => 'Pickup date cannot be in the past']);
            exit;
        }
        
        mysqli_begin_transaction($conn);
        
        try {
            $success_count = 0;
            $failed_items = [];
            $tickets = [];
            
            foreach($cart_items as $key => $item) {
                $product_id = $item['product_id'];
                $selected_color = $item['color'];
                $selected_size = $item['size'];
                $quantity = $item['quantity'];
                
                // Check product stock
                $query = "SELECT * FROM products WHERE id = ? AND status = 'active'";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "i", $product_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $product = mysqli_fetch_assoc($result);
                
                if(!$product) {
                    $failed_items[] = $item['name'] . ' - Product not found';
                    continue;
                }
                
                if($product['stock'] < $quantity) {
                    $failed_items[] = $item['name'] . ' - Insufficient stock (Available: ' . $product['stock'] . ')';
                    continue;
                }
                
                // ✅ UNIQUE TICKET NUMBER PARA SA BAWAT ITEM
                $ticket_number = 'RES-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                $tickets[] = $ticket_number;
                
                // Insert reservation
                $query = "INSERT INTO reservations 
                          (user_id, product_id, customer_name, phone, selected_color, selected_size, quantity, pickup_date, ticket_number, status, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())";
                
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "iissssiss", 
                    $user_id, 
                    $product_id, 
                    $customer_name, 
                    $phone, 
                    $selected_color, 
                    $selected_size, 
                    $quantity, 
                    $pickup_date, 
                    $ticket_number
                );
                
                if(!mysqli_stmt_execute($stmt)) {
                    $failed_items[] = $item['name'] . ' - Failed to create reservation';
                    continue;
                }
                
                // Update stock
                $new_stock = $product['stock'] - $quantity;
                $query = "UPDATE products SET stock = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ii", $new_stock, $product_id);
                
                if(!mysqli_stmt_execute($stmt)) {
                    $failed_items[] = $item['name'] . ' - Failed to update stock';
                    continue;
                }
                
                // Remove from cart
                unset($_SESSION['cart'][$key]);
                $success_count++;
            }
            
            mysqli_commit($conn);
            
            echo json_encode([
                'success' => true,
                'success_count' => $success_count,
                'failed_count' => count($failed_items),
                'failed_items' => $failed_items,
                'tickets' => $tickets,
                'message' => "$success_count item(s) reserved successfully. " . count($failed_items) . " item(s) failed."
            ]);
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'message' => 'Reservation failed: ' . $e->getMessage()]);
        }
        break;
        
    // ✅ NEW: CLEAR CART
    case 'clear_cart':
        $_SESSION['cart'] = [];
        echo json_encode(['success' => true]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>

