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
        
        // ✅ FIX: Get product with inventory data
        $query = "SELECT p.id, p.name, p.price, p.image, p.stock, p.quantity as product_qty,
                         COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                         COALESCE(i.reserved_quantity, 0) as reserved_stock,
                         (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock
                  FROM products p 
                  LEFT JOIN inventory i ON p.id = i.product_id 
                  WHERE p.id = ? AND p.status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($result);
        
        if($product) {
            $available_stock = $product['available_stock'] ?? 0;
            
            if($quantity > $available_stock) {
                echo json_encode([
                    'success' => false, 
                    'message' => "Only $available_stock units available (out of stock or reserved)"
                ]);
                exit;
            }
            
            $found = false;
            if(isset($_SESSION['cart'])) {
                foreach($_SESSION['cart'] as $key => $item) {
                    if($item['product_id'] == $product_id && $item['color'] == $color && $item['size'] == $size) {
                        $new_total = $item['quantity'] + $quantity;
                        if($new_total > $available_stock) {
                            echo json_encode([
                                'success' => false, 
                                'message' => "Cannot add $quantity more. Only $available_stock available total."
                            ]);
                            exit;
                        }
                        $_SESSION['cart'][$key]['quantity'] = $new_total;
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
                    'stock' => $available_stock,
                    'total_stock' => $product['total_stock'],
                    'reserved_stock' => $product['reserved_stock']
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
        
        // ✅ FIX: Get product with inventory data
        $query = "SELECT p.*, 
                         COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                         COALESCE(i.reserved_quantity, 0) as reserved_stock,
                         (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock
                  FROM products p 
                  LEFT JOIN inventory i ON p.id = i.product_id 
                  WHERE p.id = ? AND p.status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if($row = mysqli_fetch_assoc($result)) {
            echo json_encode([
                'success' => true,
                'colors' => $row['colors'] ?? 'Standard',
                'sizes' => $row['sizes'] ?? 'One Size',
                'stock' => (int)($row['available_stock'] ?? 0),
                'total_stock' => (int)($row['total_stock'] ?? 0),
                'reserved_stock' => (int)($row['reserved_stock'] ?? 0),
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
        
        // ✅ FIX: Get product with inventory data
        $query = "SELECT p.*, 
                         i.id as inventory_id,
                         COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                         COALESCE(i.reserved_quantity, 0) as reserved_stock,
                         (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock
                  FROM products p 
                  LEFT JOIN inventory i ON p.id = i.product_id 
                  WHERE p.id = ? AND p.status = 'active'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($result);
        
        if(!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found or inactive']);
            exit;
        }
        
        $available_stock = $product['available_stock'] ?? 0;
        
        if($available_stock < $quantity) {
            echo json_encode([
                'success' => false, 
                'message' => 'Insufficient available stock. Available: ' . $available_stock . ' (Reserved: ' . ($product['reserved_stock'] ?? 0) . ')'
            ]);
            exit;
        }
        
        // ✅ GENERATE UNIQUE TICKET NUMBER
        $ticket_number = 'RES-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        
        mysqli_begin_transaction($conn);
        
        try {
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
                throw new Exception('Failed to create reservation: ' . mysqli_error($conn));
            }
            
            $reservation_id = mysqli_insert_id($conn);
            
            // ✅ FIX: Update inventory (reserved_quantity) instead of just product stock
            if(isset($product['inventory_id']) && $product['inventory_id']) {
                // Update inventory table
                $update_inv = "UPDATE inventory 
                               SET reserved_quantity = reserved_quantity + ?,
                                   last_updated = NOW()
                               WHERE product_id = ?";
                $stmt_inv = mysqli_prepare($conn, $update_inv);
                mysqli_stmt_bind_param($stmt_inv, "ii", $quantity, $product_id);
                mysqli_stmt_execute($stmt_inv);
                
                // Update products table
                $update_prod = "UPDATE products 
                                SET reserved_stock = reserved_stock + ?,
                                    available_stock = (current_stock - reserved_stock)
                                WHERE id = ?";
                $stmt_prod = mysqli_prepare($conn, $update_prod);
                mysqli_stmt_bind_param($stmt_prod, "ii", $quantity, $product_id);
                mysqli_stmt_execute($stmt_prod);
                
                // Log inventory change
                $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, reference_id, reference_type, created_at) 
                              VALUES (?, 'reservation_out', ?, ?, 'reservation', NOW())";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, "iii", $product_id, $quantity, $reservation_id);
                mysqli_stmt_execute($log_stmt);
            } else {
                // Fallback to old method if inventory record doesn't exist
                $new_stock = ($product['stock'] ?? 0) - $quantity;
                $update_stock = "UPDATE products SET stock = ? WHERE id = ?";
                $stmt_stock = mysqli_prepare($conn, $update_stock);
                mysqli_stmt_bind_param($stmt_stock, "ii", $new_stock, $product_id);
                mysqli_stmt_execute($stmt_stock);
            }
            
            // Create notification
            $notif_query = "INSERT INTO notifications (user_id, title, message, type, reservation_code, created_at) 
                            VALUES (?, 'Reservation Pending', ?, 'pending', ?, NOW())";
            $notif_msg = "Your reservation for " . $product['name'] . " (x" . $quantity . ") is pending confirmation.";
            $notif_stmt = mysqli_prepare($conn, $notif_query);
            mysqli_stmt_bind_param($notif_stmt, "iss", $user_id, $notif_msg, $ticket_number);
            mysqli_stmt_execute($notif_stmt);
            
            // Remove item from cart session if cart_key provided
            if(!empty($cart_key) && isset($_SESSION['cart'][$cart_key])) {
                unset($_SESSION['cart'][$cart_key]);
            } else {
                // If no cart_key, try to find and remove matching item
                foreach($_SESSION['cart'] as $key => $item) {
                    if($item['product_id'] == $product_id && 
                       $item['color'] == $selected_color && 
                       $item['size'] == $selected_size) {
                        unset($_SESSION['cart'][$key]);
                        break;
                    }
                }
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
        
    // ✅ RESERVE ALL ITEMS - EACH ITEM HAS OWN TICKET
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
                
                // ✅ FIX: Get product with inventory data
                $query = "SELECT p.*, 
                                 i.id as inventory_id,
                                 COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                                 COALESCE(i.reserved_quantity, 0) as reserved_stock,
                                 (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock
                          FROM products p 
                          LEFT JOIN inventory i ON p.id = i.product_id 
                          WHERE p.id = ? AND p.status = 'active'";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "i", $product_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $product = mysqli_fetch_assoc($result);
                
                if(!$product) {
                    $failed_items[] = $item['name'] . ' - Product not found';
                    continue;
                }
                
                $available_stock = $product['available_stock'] ?? 0;
                
                if($available_stock < $quantity) {
                    $failed_items[] = $item['name'] . " - Insufficient stock (Available: $available_stock)";
                    continue;
                }
                
                // ✅ UNIQUE TICKET NUMBER FOR EACH ITEM
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
                
                $reservation_id = mysqli_insert_id($conn);
                
                // ✅ FIX: Update inventory
                if(isset($product['inventory_id']) && $product['inventory_id']) {
                    // Update inventory table
                    $update_inv = "UPDATE inventory 
                                   SET reserved_quantity = reserved_quantity + ?,
                                       last_updated = NOW()
                                   WHERE product_id = ?";
                    $stmt_inv = mysqli_prepare($conn, $update_inv);
                    mysqli_stmt_bind_param($stmt_inv, "ii", $quantity, $product_id);
                    mysqli_stmt_execute($stmt_inv);
                    
                    // Update products table
                    $update_prod = "UPDATE products 
                                    SET reserved_stock = reserved_stock + ?,
                                        available_stock = (current_stock - reserved_stock)
                                    WHERE id = ?";
                    $stmt_prod = mysqli_prepare($conn, $update_prod);
                    mysqli_stmt_bind_param($stmt_prod, "ii", $quantity, $product_id);
                    mysqli_stmt_execute($stmt_prod);
                    
                    // Log inventory change
                    $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, reference_id, reference_type, created_at) 
                                  VALUES (?, 'reservation_out', ?, ?, 'reservation', NOW())";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, "iii", $product_id, $quantity, $reservation_id);
                    mysqli_stmt_execute($log_stmt);
                } else {
                    // Fallback
                    $new_stock = ($product['stock'] ?? 0) - $quantity;
                    $update_stock = "UPDATE products SET stock = ? WHERE id = ?";
                    $stmt_stock = mysqli_prepare($conn, $update_stock);
                    mysqli_stmt_bind_param($stmt_stock, "ii", $new_stock, $product_id);
                    mysqli_stmt_execute($stmt_stock);
                }
                
                // Create notification
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, reservation_code, created_at) 
                                VALUES (?, 'Reservation Pending', ?, 'pending', ?, NOW())";
                $notif_msg = "Your reservation for " . $item['name'] . " (x$quantity) is pending confirmation.";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $user_id, $notif_msg, $ticket_number);
                mysqli_stmt_execute($notif_stmt);
                
                // Remove from cart session later
                $success_count++;
            }
            
            // Clear successful items from cart
            $_SESSION['cart'] = [];
            
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
        
    // ✅ CLEAR CART
    case 'clear_cart':
        $_SESSION['cart'] = [];
        echo json_encode(['success' => true]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>