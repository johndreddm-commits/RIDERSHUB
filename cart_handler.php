<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 para hindi lumabas ang errors sa output

session_start();
require_once 'config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Clear any output buffering
ob_clean();

$response = ['success' => false, 'message' => 'Invalid action'];

// Get action from POST
$action = $_POST['action'] ?? '';

switch($action) {
    
    case 'add_to_cart':
        $product_id = intval($_POST['product_id'] ?? 0);
        $color = $_POST['color'] ?? 'Standard';
        $size = $_POST['size'] ?? 'One Size';
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if($product_id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid product'];
            break;
        }
        
        // Get product details
        $query = "SELECT p.*, COALESCE(i.quantity, 0) as stock 
                  FROM products p 
                  LEFT JOIN inventory i ON p.id = i.product_id 
                  WHERE p.id = ? AND p.status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($result);
        
        if(!$product) {
            $response = ['success' => false, 'message' => 'Product not found'];
            break;
        }
        
        $available_stock = $product['stock'] ?? 0;
        
        if($quantity > $available_stock) {
            $response = ['success' => false, 'message' => 'Not enough stock available'];
            break;
        }
        
        // Initialize cart if not exists
        if(!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        // Generate unique key for cart item
        $cart_key = $product_id . '_' . $color . '_' . $size;
        
        // Add to cart
        $_SESSION['cart'][$cart_key] = [
            'product_id' => $product_id,
            'name' => $product['name'],
            'price' => $product['price'],
            'color' => $color,
            'size' => $size,
            'quantity' => $quantity,
            'image' => $product['image']
        ];
        
        // Calculate cart count
        $cart_count = 0;
        foreach($_SESSION['cart'] as $item) {
            $cart_count += $item['quantity'];
        }
        
        $response = [
            'success' => true, 
            'message' => 'Item added to cart',
            'cart_count' => $cart_count
        ];
        break;
        
    case 'remove_item':
        $cart_key = $_POST['cart_key'] ?? '';
        
        if(isset($_SESSION['cart'][$cart_key])) {
            unset($_SESSION['cart'][$cart_key]);
            
            $cart_count = 0;
            foreach($_SESSION['cart'] as $item) {
                $cart_count += $item['quantity'];
            }
            
            $response = [
                'success' => true,
                'message' => 'Item removed',
                'cart_count' => $cart_count
            ];
        } else {
            $response = ['success' => false, 'message' => 'Item not found'];
        }
        break;
        
    case 'update_quantity':
        $cart_key = $_POST['cart_key'] ?? '';
        $change = intval($_POST['change'] ?? 0);
        
        if(isset($_SESSION['cart'][$cart_key])) {
            $item = &$_SESSION['cart'][$cart_key];
            $new_quantity = $item['quantity'] + $change;
            
            if($new_quantity >= 1) {
                $item['quantity'] = $new_quantity;
            }
            
            $cart_count = 0;
            foreach($_SESSION['cart'] as $i) {
                $cart_count += $i['quantity'];
            }
            
            $response = [
                'success' => true,
                'message' => 'Quantity updated',
                'cart_count' => $cart_count
            ];
        } else {
            $response = ['success' => false, 'message' => 'Item not found'];
        }
        break;
        
    case 'get_product_details':
        $product_id = intval($_POST['id'] ?? 0);
        
        $query = "SELECT p.*, COALESCE(i.quantity, 0) as stock,
                         COALESCE(i.reserved_quantity, 0) as reserved_stock
                  FROM products p 
                  LEFT JOIN inventory i ON p.id = i.product_id 
                  WHERE p.id = ? AND p.status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($result);
        
        if($product) {
            $response = [
                'success' => true,
                'stock' => $product['stock'] ?? 0,
                'total_stock' => $product['stock'] ?? 0,
                'reserved_stock' => $product['reserved_stock'] ?? 0,
                'colors' => $product['colors'] ?? 'Standard',
                'sizes' => $product['sizes'] ?? 'One Size',
                'price' => $product['price'] ?? 0
            ];
        } else {
            $response = ['success' => false, 'message' => 'Product not found'];
        }
        break;
        
    case 'reserve_from_cart':
        $product_id = intval($_POST['product_id'] ?? 0);
        $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
        $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
        $color = mysqli_real_escape_string($conn, $_POST['selected_color'] ?? 'Standard');
        $size = mysqli_real_escape_string($conn, $_POST['selected_size'] ?? 'One Size');
        $quantity = intval($_POST['quantity'] ?? 1);
        $pickup_date = mysqli_real_escape_string($conn, $_POST['pickup_date'] ?? '');
        $user_id = $_SESSION['id'] ?? 0;
        $cart_key = $_POST['cart_key'] ?? '';
        
        if($user_id <= 0) {
            $response = ['success' => false, 'message' => 'Please login first'];
            break;
        }
        
        // Generate ticket number
        $ticket_number = 'RES-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        
        mysqli_begin_transaction($conn);
        
        try {
            // Check stock
            $stock_query = "SELECT quantity, reserved_quantity FROM inventory WHERE product_id = ?";
            $stock_stmt = mysqli_prepare($conn, $stock_query);
            mysqli_stmt_bind_param($stock_stmt, "i", $product_id);
            mysqli_stmt_execute($stock_stmt);
            $stock_result = mysqli_stmt_get_result($stock_stmt);
            $stock_data = mysqli_fetch_assoc($stock_result);
            
            $current_stock = $stock_data['quantity'] ?? 0;
            $current_reserved = $stock_data['reserved_quantity'] ?? 0;
            
            if($quantity > $current_stock) {
                throw new Exception("Not enough stock available");
            }
            
            // Insert reservation
            $insert_query = "INSERT INTO reservations 
                            (ticket_number, user_id, product_id, customer_name, phone, 
                             selected_color, selected_size, quantity, pickup_date, status, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "siissssis", 
                $ticket_number, $user_id, $product_id, $customer_name, $phone,
                $color, $size, $quantity, $pickup_date
            );
            
            if(!mysqli_stmt_execute($insert_stmt)) {
                throw new Exception("Failed to create reservation");
            }
            
            // Update reserved stock
            $new_reserved = $current_reserved + $quantity;
            $update_inv = "UPDATE inventory SET reserved_quantity = ? WHERE product_id = ?";
            $update_stmt = mysqli_prepare($conn, $update_inv);
            mysqli_stmt_bind_param($update_stmt, "ii", $new_reserved, $product_id);
            mysqli_stmt_execute($update_stmt);
            
            // Log the action
            $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, reference_id, created_at) 
                          VALUES (?, 'reserved', ?, ?, NOW())";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "iii", $product_id, $quantity, mysqli_insert_id($conn));
            mysqli_stmt_execute($log_stmt);
            
            // Remove from cart if it came from cart
            if(!empty($cart_key) && isset($_SESSION['cart'][$cart_key])) {
                unset($_SESSION['cart'][$cart_key]);
            }
            
            mysqli_commit($conn);
            
            $response = [
                'success' => true,
                'message' => 'Reservation created',
                'ticket' => $ticket_number
            ];
            
        } catch(Exception $e) {
            mysqli_rollback($conn);
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        break;
        
    case 'reserve_all_items':
        $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
        $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
        $pickup_date = mysqli_real_escape_string($conn, $_POST['pickup_date'] ?? '');
        $user_id = $_SESSION['id'] ?? 0;
        
        if($user_id <= 0) {
            $response = ['success' => false, 'message' => 'Please login first'];
            break;
        }
        
        if(empty($_SESSION['cart'])) {
            $response = ['success' => false, 'message' => 'Cart is empty'];
            break;
        }
        
        mysqli_begin_transaction($conn);
        
        $success_count = 0;
        $failed_count = 0;
        $tickets = [];
        
        try {
            foreach($_SESSION['cart'] as $key => $item) {
                $product_id = $item['product_id'];
                $color = $item['color'];
                $size = $item['size'];
                $quantity = $item['quantity'];
                
                // Check stock
                $stock_query = "SELECT quantity FROM inventory WHERE product_id = ?";
                $stock_stmt = mysqli_prepare($conn, $stock_query);
                mysqli_stmt_bind_param($stock_stmt, "i", $product_id);
                mysqli_stmt_execute($stock_stmt);
                $stock_result = mysqli_stmt_get_result($stock_stmt);
                $stock_data = mysqli_fetch_assoc($stock_result);
                $current_stock = $stock_data['quantity'] ?? 0;
                
                if($quantity > $current_stock) {
                    $failed_count++;
                    continue;
                }
                
                // Generate ticket
                $ticket_number = 'RES-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                
                // Insert reservation
                $insert_query = "INSERT INTO reservations 
                                (ticket_number, user_id, product_id, customer_name, phone, 
                                 selected_color, selected_size, quantity, pickup_date, status, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($insert_stmt, "siissssis", 
                    $ticket_number, $user_id, $product_id, $customer_name, $phone,
                    $color, $size, $quantity, $pickup_date
                );
                
                if(mysqli_stmt_execute($insert_stmt)) {
                    // Update reserved stock
                    $update_inv = "UPDATE inventory SET reserved_quantity = reserved_quantity + ? WHERE product_id = ?";
                    $update_stmt = mysqli_prepare($conn, $update_inv);
                    mysqli_stmt_bind_param($update_stmt, "ii", $quantity, $product_id);
                    mysqli_stmt_execute($update_stmt);
                    
                    // Log
                    $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, reference_id, created_at) 
                                  VALUES (?, 'reserved', ?, ?, NOW())";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, "iii", $product_id, $quantity, mysqli_insert_id($conn));
                    mysqli_stmt_execute($log_stmt);
                    
                    $success_count++;
                    $tickets[] = $ticket_number;
                } else {
                    $failed_count++;
                }
            }
            
            // Clear cart
            $_SESSION['cart'] = [];
            
            mysqli_commit($conn);
            
            $response = [
                'success' => true,
                'success_count' => $success_count,
                'failed_count' => $failed_count,
                'tickets' => $tickets
            ];
            
        } catch(Exception $e) {
            mysqli_rollback($conn);
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        break;
        
    default:
        $response = ['success' => false, 'message' => 'Unknown action'];
}

// Clear output buffer and send JSON
ob_clean();
echo json_encode($response);
exit;
?>