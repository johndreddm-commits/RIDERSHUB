<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();
require_once "config.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Initialize cart
if(!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Calculate cart total and count
$cart_total = 0;
$cart_count = 0;
$cart_items = $_SESSION['cart'] ?? [];

// Update cart items with latest inventory data
$updated_cart = [];
foreach($cart_items as $key => $item) {
    $product_id = $item['product_id'];
    
    // Get latest inventory data for each cart item
    $query = "SELECT p.*, 
                     COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                     COALESCE(i.reserved_quantity, 0) as reserved_stock,
                     GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock
              FROM products p 
              LEFT JOIN inventory i ON p.id = i.product_id 
              WHERE p.id = ? AND p.status = 'active'";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $product_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $product = mysqli_fetch_assoc($result);
    
    if($product) {
        $item['available_stock'] = $product['available_stock'] ?? 0;
        $item['total_stock'] = $product['total_stock'] ?? 0;
        $item['reserved_stock'] = $product['reserved_stock'] ?? 0;
        $item['price'] = $product['price'];
        
        // If quantity exceeds available stock, adjust it
        if($item['quantity'] > $item['available_stock']) {
            $item['quantity'] = max(0, $item['available_stock']);
        }
        
        $updated_cart[$key] = $item;
        $cart_total += $item['price'] * $item['quantity'];
        $cart_count += $item['quantity'];
    } else {
        // Product no longer active, skip it
        continue;
    }
}
$_SESSION['cart'] = $updated_cart;
$cart_items = $updated_cart;

// Create array of cart items for JavaScript
$js_cart_items = [];
foreach($cart_items as $key => $item) {
    $js_cart_items[] = [
        'key' => $key,
        'product_id' => $item['product_id'],
        'name' => $item['name'],
        'color' => $item['color'],
        'size' => $item['size'],
        'quantity' => $item['quantity'],
        'price' => $item['price'],
        'image' => $item['image'],
        'available_stock' => $item['available_stock'] ?? 0,
        'total_stock' => $item['total_stock'] ?? 0,
        'reserved_stock' => $item['reserved_stock'] ?? 0
    ];
}

// Handle delete reservation action
if(isset($_GET['delete_reservation'])) {
    $reservation_id = (int)$_GET['delete_reservation'];
    $user_id = $_SESSION['id'] ?? 0;
    
    // Verify the reservation belongs to the user
    $check_query = "SELECT id, product_id, quantity, status, ticket_number FROM reservations WHERE id = ? AND user_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $reservation_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $reservation = mysqli_fetch_assoc($check_result);
    
    if($reservation) {
        if($reservation['status'] == 'CONFIRMED') {
            $_SESSION['error'] = "Cannot delete confirmed reservations. Please contact support.";
        } else {
            // HARD DELETE - Only for pending/cancelled reservations
            $delete_query = "DELETE FROM reservations WHERE id = ? AND user_id = ?";
            $delete_stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($delete_stmt, "ii", $reservation_id, $user_id);
            
            if(mysqli_stmt_execute($delete_stmt)) {
                // Add notification for deleted reservation
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, reservation_code, created_at) 
                                VALUES (?, 'Reservation Deleted', ?, 'cancelled', ?, NOW())";
                $notif_msg = "Your reservation for " . $reservation['ticket_number'] . " has been deleted.";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $user_id, $notif_msg, $reservation['ticket_number']);
                mysqli_stmt_execute($notif_stmt);
                
                $_SESSION['message'] = "Reservation deleted successfully!";
            } else {
                $_SESSION['error'] = "Failed to delete reservation.";
            }
        }
    } else {
        $_SESSION['error'] = "Reservation not found or you don't have permission to delete it.";
    }
    
    header("Location: cart.php");
    exit;
}

// Mark notifications as read
if(isset($_GET['mark_read'])) {
    $notification_id = (int)$_GET['mark_read'];
    $user_id = $_SESSION['id'] ?? 0;
    
    if($notification_id > 0 && $user_id > 0) {
        $update_query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "ii", $notification_id, $user_id);
        mysqli_stmt_execute($update_stmt);
    }
    header("Location: cart.php");
    exit;
}

// Mark all notifications as read
if(isset($_GET['mark_all_read'])) {
    $user_id = $_SESSION['id'] ?? 0;
    
    if($user_id > 0) {
        $update_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "i", $user_id);
        if(mysqli_stmt_execute($update_stmt)) {
            $_SESSION['message'] = "All notifications marked as read!";
        } else {
            $_SESSION['error'] = "Failed to mark notifications as read.";
        }
    }
    
    header("Location: cart.php");
    exit;
}

// Fetch user's notifications
$user_id = $_SESSION['id'] ?? 0;
$notifications_query = "SELECT * FROM notifications 
                       WHERE user_id = ? 
                       ORDER BY created_at DESC 
                       LIMIT 20";
$notifications_stmt = mysqli_prepare($conn, $notifications_query);
mysqli_stmt_bind_param($notifications_stmt, "i", $user_id);
mysqli_stmt_execute($notifications_stmt);
$notifications_result = mysqli_stmt_get_result($notifications_stmt);
$unread_count = 0;

if ($notifications_result && mysqli_num_rows($notifications_result) > 0) {
    $unread_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    $unread_stmt = mysqli_prepare($conn, $unread_query);
    mysqli_stmt_bind_param($unread_stmt, "i", $user_id);
    mysqli_stmt_execute($unread_stmt);
    $unread_result = mysqli_stmt_get_result($unread_stmt);
    if ($unread_result) {
        $unread_count = mysqli_fetch_assoc($unread_result)['count'];
    }
} else {
    // Create notifications table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) DEFAULT 'general',
        reservation_code VARCHAR(50),
        is_read TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (is_read)
    )";
    mysqli_query($conn, $create_table);
}

// Fetch user's recent reservations with inventory data
$recent_reservations_query = "SELECT r.*, p.name as product_name, p.image as product_image, p.price, 
                              p.colors, p.sizes, b.brand_name,
                              COALESCE(i.quantity, p.quantity, p.stock, 0) as current_stock,
                              COALESCE(i.reserved_quantity, 0) as current_reserved
                              FROM reservations r 
                              LEFT JOIN products p ON r.product_id = p.id 
                              LEFT JOIN brands b ON p.brand_id = b.id 
                              LEFT JOIN inventory i ON p.id = i.product_id 
                              WHERE r.user_id = ? 
                              ORDER BY r.created_at DESC 
                              LIMIT 10";

$recent_reservations_stmt = mysqli_prepare($conn, $recent_reservations_query);
mysqli_stmt_bind_param($recent_reservations_stmt, "i", $user_id);
mysqli_stmt_execute($recent_reservations_stmt);
$recent_reservations_result = mysqli_stmt_get_result($recent_reservations_stmt);

// Get low stock warnings for user
$low_stock_query = "SELECT COUNT(DISTINCT r.product_id) as low_stock_count
                    FROM reservations r
                    JOIN products p ON r.product_id = p.id
                    LEFT JOIN inventory i ON p.id = i.product_id
                    WHERE r.user_id = ? 
                    AND r.status = 'PENDING'
                    AND GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) <= 5";
$low_stock_stmt = mysqli_prepare($conn, $low_stock_query);
mysqli_stmt_bind_param($low_stock_stmt, "i", $user_id);
mysqli_stmt_execute($low_stock_stmt);
$low_stock_result = mysqli_stmt_get_result($low_stock_stmt);
$low_stock_count = $low_stock_result->fetch_assoc()['low_stock_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Audiowide&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <title>Your Cart - KB Ridershub</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #000;
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            overflow-x: hidden;
        }
        
        /* ====== NAVIGATION STYLES ====== */
        .nav {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 20px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(15px);
            border-bottom: 2px solid #ff0000;
            box-shadow: 0 5px 30px rgba(255, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .nav.scrolled {
            padding: 10px 60px;
            background: rgba(0, 0, 0, 0.98);
            box-shadow: 0 5px 40px rgba(255, 0, 0, 0.3);
        }

        .nav.scrolled .nav-logo {
            width: 55px;
            height: 55px;
        }

        .nav.scrolled .nav-title {
            font-size: 24px;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .nav-left:hover {
            transform: scale(1.02);
        }

        .nav-logo {
            width: 65px;
            height: 65px;
            filter: drop-shadow(0 0 15px rgba(255, 0, 0, 0.8));
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .nav-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 3px;
            background: linear-gradient(90deg, #fff, #ff0000, #fff);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shine 3s linear infinite;
        }

        @keyframes shine {
            to { background-position: 200% center; }
        }

        .nav-menu {
            display: flex;
            list-style: none;
            gap: 30px;
            align-items: center;
        }

        .nav-menu li {
            position: relative;
            animation: fadeInNav 0.5s ease forwards;
            opacity: 0;
        }

        .nav-menu li:nth-child(1) { animation-delay: 0.1s; }
        .nav-menu li:nth-child(2) { animation-delay: 0.15s; }
        .nav-menu li:nth-child(3) { animation-delay: 0.2s; }
        .nav-menu li:nth-child(4) { animation-delay: 0.25s; }
        .nav-menu li:nth-child(5) { animation-delay: 0.3s; }
        .nav-menu li:nth-child(6) { animation-delay: 0.35s; }

        @keyframes fadeInNav {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .nav-menu li a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
            padding: 8px 12px;
            display: block;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .nav-menu li a::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 0, 0, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
            z-index: -1;
        }

        .nav-menu li a:hover::before {
            width: 200px;
            height: 200px;
        }

        .nav-menu li a:hover {
            color: red;
        }

        .nav-menu li a i {
            margin-right: 5px;
            transition: transform 0.3s ease;
        }

        .nav-menu li a:hover i {
            transform: rotate(360deg);
        }

        /* Active Navigation Style */
        .nav-menu li.active > a {
            color: red;
            position: relative;
        }

        .nav-menu li.active > a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 12px;
            right: 12px;
            height: 2px;
            background: red;
            border-radius: 2px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                width: 0;
                opacity: 0;
            }
            to {
                width: calc(100% - 24px);
                opacity: 1;
            }
        }

        /* Dropdown Menu */
        .dropdown {
            position: relative;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            background: rgba(0, 0, 0, 0.95);
            border: 1px solid red;
            border-radius: 8px;
            padding: 10px 0;
            min-width: 220px;
            display: none;
            z-index: 1000;
            backdrop-filter: blur(15px);
            box-shadow: 0 10px 30px rgba(255, 0, 0, 0.2);
            animation: dropdownFade 0.3s ease;
        }

        @keyframes dropdownFade {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown:hover .dropdown-menu {
            display: block;
        }

        .dropdown-menu li {
            list-style: none;
            margin: 0;
        }

        .dropdown-menu li a {
            padding: 12px 25px;
            color: #ccc;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }

        .dropdown-menu li a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 100%;
            background: rgba(255, 0, 0, 0.1);
            transition: width 0.3s ease;
            z-index: -1;
        }

        .dropdown-menu li a:hover::before {
            width: 100%;
        }

        .dropdown-menu li a:hover {
            background: rgba(255, 0, 0, 0.15);
            color: red;
            padding-left: 35px;
            transform: translateX(5px);
        }

        .dropdown-menu li a i {
            margin-right: 10px;
            font-size: 0.9rem;
            color: red;
            opacity: 0.7;
            transition: transform 0.3s ease;
        }

        .dropdown-menu li a:hover i {
            transform: scale(1.2);
        }

        /* Cart Count */
        .cart-count {
            background: red;
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 50%;
            min-width: 18px;
            text-align: center;
            font-family: 'Orbitron', sans-serif;
            margin-left: 5px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }

        /* Low Stock Warning */
        .low-stock-warning {
            background: #ff9900;
            color: #000;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
            animation: pulse 2s infinite;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* NOTIFICATION BELL STYLES - MOVED TO RIGHT SIDE */
        .notification-container {
            position: relative;
            display: inline-block;
            margin-left: 15px;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            color: white;
            font-size: 1.3rem;
            transition: all 0.3s ease;
        }

        .notification-bell:hover {
            color: red;
            transform: rotate(15deg);
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: red;
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            border: 2px solid #000;
            animation: pulse 2s infinite;
        }

        /* NOTIFICATION DROPDOWN */
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 380px;
            background: #111;
            border: 1px solid red;
            border-radius: 12px;
            margin-top: 15px;
            display: none;
            z-index: 1001;
            box-shadow: 0 10px 40px rgba(255, 0, 0, 0.3);
            overflow: hidden;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: #1a1a1a;
            border-bottom: 1px solid #333;
        }

        .notification-header h3 {
            color: red;
            font-size: 1.1rem;
            font-family: 'Audiowide', sans-serif;
        }

        .mark-all-read {
            background: none;
            border: none;
            color: #888;
            font-size: 0.8rem;
            cursor: pointer;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .mark-all-read:hover {
            color: red;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-list::-webkit-scrollbar {
            width: 5px;
        }

        .notification-list::-webkit-scrollbar-thumb {
            background: red;
            border-radius: 10px;
        }

        .notification-item {
            display: flex;
            gap: 15px;
            padding: 15px 20px;
            border-bottom: 1px solid #222;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .notification-item:hover {
            background: #1a1a1a;
        }

        .notification-item.unread {
            background: rgba(255, 0, 0, 0.05);
            border-left: 3px solid red;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: red;
            font-size: 1.2rem;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            color: red;
            font-weight: bold;
            font-size: 0.95rem;
            margin-bottom: 5px;
        }

        .notification-message {
            color: #ccc;
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .notification-time {
            color: #666;
            font-size: 0.7rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-status {
            color: #ffc107;
            font-size: 0.75rem;
        }

        .notification-empty {
            padding: 40px;
            text-align: center;
            color: #666;
        }

        .notification-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #333;
        }

        /* Status Badge Colors */
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: bold;
            text-transform: uppercase;
            margin-right: 5px;
        }

        .status-pending {
            background: #ffc107;
            color: #000;
        }

        .status-confirmed {
            background: #28a745;
            color: #fff;
        }

        .status-completed {
            background: #007bff;
            color: #fff;
        }

        .status-cancelled {
            background: #dc3545;
            color: #fff;
        }

        /* Mobile Navigation */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 10px;
            transition: transform 0.3s ease;
        }
        
        .mobile-menu-btn:hover {
            transform: scale(1.1);
            color: red;
        }
        
        .mobile-menu-btn:active {
            transform: scale(0.95);
        }
        
        .close-menu-btn {
            display: none;
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            z-index: 1001;
            transition: all 0.3s ease;
        }
        
        .close-menu-btn:hover {
            color: red;
            transform: rotate(90deg);
        }
        
        .mobile-search-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 10px;
            transition: all 0.3s ease;
        }
        
        .mobile-search-btn:hover {
            color: red;
            transform: scale(1.1);
        }
        
        .mobile-search-container {
            display: none;
            position: fixed;
            top: 100px;
            left: 0;
            width: 100%;
            padding: 15px;
            background: rgba(0, 0, 0, 0.95);
            z-index: 999;
            border-bottom: 1px solid red;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .mobile-search-input {
            width: 100%;
            padding: 15px 20px;
            border-radius: 8px;
            border: 1px solid red;
            background: #111;
            color: white;
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .mobile-search-input:focus {
            outline: none;
            box-shadow: 0 0 15px rgba(255, 0, 0, 0.3);
            transform: scale(1.02);
        }

        /* Recent Reservations Styles */
        .recent-reservations-section {
            max-width: 1200px;
            margin: 120px auto 0;
            padding: 0 20px;
            position: relative;
            z-index: 5;
        }

        .recent-reservations-header {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.8rem;
            color: red;
            margin-bottom: 20px;
            text-align: center;
            border-bottom: 2px solid red;
            padding-bottom: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .recent-reservations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .recent-reservation-card {
            background: #0b0b0b;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 15px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .recent-reservation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: red;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .recent-reservation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(255, 0, 0, 0.2);
            border-color: red;
        }

        .recent-reservation-card:hover::before {
            opacity: 1;
        }

        .recent-reservation-content {
            display: flex;
            gap: 15px;
        }

        .recent-reservation-image {
            width: 80px;
            height: 80px;
            object-fit: contain;
            background: #fff;
            border-radius: 8px;
            padding: 5px;
        }

        .recent-reservation-details {
            flex: 1;
        }

        .recent-reservation-name {
            color: red;
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 1rem;
        }

        .recent-reservation-brand {
            color: #888;
            font-size: 0.8rem;
            margin-bottom: 8px;
        }

        .recent-reservation-specs {
            color: #ccc;
            font-size: 0.8rem;
            margin-bottom: 5px;
        }

        .recent-reservation-specs span {
            color: #fff;
            font-weight: bold;
        }

        .recent-reservation-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #333;
            flex-wrap: wrap;
            gap: 10px;
        }

        .recent-reservation-price {
            color: red;
            font-weight: bold;
            font-size: 1rem;
        }

        .recent-reservation-code {
            background: #1a1a1a;
            color: #ffc107;
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 12px;
            letter-spacing: 1px;
            display: inline-block;
        }

        .stock-info-small {
            font-size: 0.7rem;
            color: #ffaa00;
            display: block;
        }

        .reservation-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .reserve-again-btn {
            background: red;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            position: relative;
            overflow: hidden;
        }

        .reserve-again-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .reserve-again-btn:hover::before {
            width: 200px;
            height: 200px;
        }

        .reserve-again-btn:hover {
            background: #cc0000;
            transform: scale(1.05);
        }

        .reserve-again-btn i {
            font-size: 0.9rem;
        }

        .delete-reservation-btn {
            background: transparent;
            color: #666;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 8px 15px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            position: relative;
            overflow: hidden;
        }

        .delete-reservation-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .delete-reservation-btn:hover::before {
            width: 200px;
            height: 200px;
        }

        .delete-reservation-btn:hover {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
            transform: scale(1.05);
        }

        .delete-reservation-btn i {
            font-size: 0.9rem;
        }

        .recent-empty {
            text-align: center;
            padding: 40px;
            background: #0b0b0b;
            border-radius: 12px;
            border: 1px solid #333;
            color: #666;
        }

        .recent-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #333;
        }

        /* Cart Container */
        .cart-container {
            max-width: 1200px;
            margin: 30px auto 50px;
            padding: 0 20px;
            position: relative;
            z-index: 5;
        }
        
        .cart-header {
            font-family: 'Audiowide', sans-serif;
            font-size: 2.5rem;
            color: red;
            margin-bottom: 40px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .cart-items {
            background: #0b0b0b;
            border-radius: 16px;
            padding: 30px;
            border: 1px solid #222;
        }
        
        .cart-item {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #333;
            gap: 20px;
            flex-wrap: wrap;
            position: relative;
        }
        
        .cart-item:last-child {
            border-bottom: none;
        }
        
        .cart-item img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            background: #fff;
            border-radius: 8px;
            padding: 5px;
        }
        
        .item-details {
            flex: 2;
            min-width: 200px;
        }
        
        .item-name {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: red;
        }
        
        .item-specs {
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .item-price {
            flex: 1;
            text-align: right;
            font-size: 1.2rem;
            color: red;
            font-weight: bold;
            min-width: 100px;
        }
        
        .item-quantity {
            flex: 0.5;
            text-align: center;
            min-width: 120px;
        }
        
        .quantity-control {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .quantity-btn {
            background: #333;
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .quantity-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .quantity-btn:hover::before {
            width: 60px;
            height: 60px;
        }
        
        .quantity-btn:hover:not(:disabled) {
            background: red;
        }

        .quantity-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .quantity-input {
            width: 50px;
            text-align: center;
            background: #1a1a1a;
            border: 1px solid #333;
            color: white;
            padding: 5px;
            border-radius: 5px;
        }
        
        .item-remove {
            flex: 0.3;
            text-align: center;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            min-width: 120px;
        }
        
        .remove-btn {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .remove-btn:hover {
            color: red;
        }
        
        .cart-summary {
            margin-top: 30px;
            padding: 20px;
            background: #111;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .cart-total {
            font-size: 1.5rem;
        }
        
        .total-amount {
            color: red;
            font-weight: bold;
        }
        
        .reserve-btn {
            background: red;
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 1.1rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .reserve-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .reserve-btn:hover::before {
            width: 400px;
            height: 400px;
        }
        
        .reserve-btn:hover:not(:disabled) {
            background: #cc0000;
            transform: translateY(-2px);
        }

        .reserve-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .empty-cart {
            text-align: center;
            padding: 50px;
            color: #666;
        }
        
        .empty-cart i {
            font-size: 5rem;
            margin-bottom: 20px;
            color: #333;
            animation: bounce 2s infinite;
        }
        
        .empty-cart h2 {
            margin-bottom: 20px;
        }
        
        .continue-shopping {
            display: inline-block;
            background: red;
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            margin-top: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .continue-shopping::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .continue-shopping:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .continue-shopping:hover {
            background: #cc0000;
            transform: translateY(-2px);
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-family: 'Orbitron', sans-serif;
            border-left: 4px solid;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left-color: #28a745;
            color: #28a745;
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            border-left-color: #dc3545;
            color: #dc3545;
        }

        .alert i {
            margin-right: 10px;
        }
        
        /* MODAL STYLES */
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 9999; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.85); 
            backdrop-filter: blur(8px); 
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        .modal-content { 
            background-color: #111; 
            margin: 5% auto; 
            padding: 25px; 
            border: 2px solid red; 
            width: 90%; 
            max-width: 450px; 
            max-height: 90vh; 
            overflow-y: auto; 
            border-radius: 15px; 
            color: white; 
            font-family: 'Orbitron', sans-serif; 
            position: relative;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-content::-webkit-scrollbar { 
            width: 5px; 
        }
        
        .modal-content::-webkit-scrollbar-thumb { 
            background: red; 
            border-radius: 10px; 
        }
        
        #reservationForm label,
        #reserveAgainForm label { 
            display: block; 
            margin-top: 15px; 
            font-size: 0.8rem; 
            color: #ccc; 
        }
        
        #reservationForm input, 
        #reservationForm select,
        #reserveAgainForm input, 
        #reserveAgainForm select { 
            width: 100%; 
            padding: 12px; 
            margin-top: 5px; 
            background: #1a1a1a; 
            border: 1px solid #333; 
            color: white; 
            border-radius: 8px; 
            box-sizing: border-box; 
            font-family: 'Orbitron', sans-serif;
            transition: all 0.3s ease;
        }

        #reservationForm input:focus, 
        #reservationForm select:focus,
        #reserveAgainForm input:focus, 
        #reserveAgainForm select:focus { 
            border-color: red; 
            outline: none;
            transform: scale(1.02);
        }
        
        .submit-res-btn { 
            width: 100%; 
            background: red; 
            color: white; 
            padding: 15px; 
            border: none; 
            font-weight: bold; 
            cursor: pointer; 
            border-radius: 8px; 
            margin-top: 25px; 
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .submit-res-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .submit-res-btn:hover::before {
            width: 500px;
            height: 500px;
        }
        
        .submit-res-btn:hover:not(:disabled) {
            background: #cc0000;
        }

        .submit-res-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .selection-row { 
            display: flex; 
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .selection-row div { 
            flex: 1;
            min-width: 150px;
        }
        
        .stock-tag {
            background: rgba(255, 0, 0, 0.1);
            color: #00ff00;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8rem;
            display: inline-block;
            margin-bottom: 10px;
            border: 1px solid #00ff0033;
            transition: all 0.3s ease;
        }

        .stock-tag:hover {
            transform: scale(1.05);
            background: rgba(0, 255, 0, 0.15);
        }

        .stock-tag.low-stock {
            color: #ff9900;
            border-color: #ff990033;
        }

        .stock-tag.out-of-stock {
            color: #ff0000;
            border-color: #ff000033;
        }
        
        .close-modal {
            color: #666;
            position: absolute;
            right: 20px;
            top: 10px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .close-modal:hover {
            color: red;
            transform: rotate(90deg);
        }
        
        .modal h2 {
            color: red;
            margin-bottom: 20px;
            font-family: 'Audiowide', sans-serif;
        }
        
        .reserve-item-btn {
            background: red;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .reserve-item-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .reserve-item-btn:hover::before {
            width: 150px;
            height: 150px;
        }
        
        .reserve-item-btn:hover:not(:disabled) {
            background: #cc0000;
        }

        .reserve-item-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        /* RESERVE ALL MODAL STYLES */
        .reserve-all-modal .modal-content { 
            max-width: 550px; 
        }
        
        .cart-items-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 20px;
            padding-right: 10px;
        }
        
        .cart-items-list::-webkit-scrollbar {
            width: 5px;
        }
        
        .cart-items-list::-webkit-scrollbar-thumb {
            background: red;
            border-radius: 10px;
        }
        
        .cart-item-preview {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
        }

        .cart-item-preview:hover {
            transform: translateX(5px);
            border-color: red;
        }
        
        .cart-item-preview img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            background: #fff;
            border-radius: 5px;
            padding: 3px;
        }
        
        .cart-item-preview-details {
            flex: 1;
        }
        
        .cart-item-preview-name {
            color: red;
            font-size: 0.95rem;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .cart-item-preview-specs {
            color: #ccc;
            font-size: 0.8rem;
        }
        
        .cart-item-preview-price {
            color: red;
            font-weight: bold;
            font-size: 0.95rem;
        }
        
        .cart-item-preview-quantity {
            background: #333;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }

        .cart-item-preview-stock {
            font-size: 0.7rem;
            color: #ffaa00;
            margin-top: 2px;
        }
        
        .reservation-summary {
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .reservation-summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }
        
        .reservation-summary-row:last-child {
            margin-bottom: 0;
        }
        
        .reservation-summary-label {
            color: #ccc;
        }
        
        .reservation-summary-value {
            color: red;
            font-weight: bold;
        }
        
        .customer-details {
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .customer-details h3 {
            color: red;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .customer-details input {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            background: #1a1a1a;
            border: 1px solid #333;
            color: white;
            border-radius: 5px;
            font-family: 'Orbitron', sans-serif;
            transition: all 0.3s ease;
        }

        .customer-details input:focus {
            border-color: red;
            outline: none;
            transform: scale(1.02);
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
        }
        
        .modal-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            font-family: 'Orbitron', sans-serif;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .modal-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .modal-btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .modal-btn-primary {
            background: red;
            color: white;
        }
        
        .modal-btn-primary:hover:not(:disabled) {
            background: #cc0000;
            transform: translateY(-2px);
        }

        .modal-btn-primary:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .modal-btn-secondary {
            background: #333;
            color: white;
        }
        
        .modal-btn-secondary:hover {
            background: #444;
            transform: translateY(-2px);
        }
        
        .pickup-date-input {
            width: 100%;
            padding: 10px;
            background: #1a1a1a;
            border: 1px solid #333;
            color: white;
            border-radius: 5px;
            font-family: 'Orbitron', sans-serif;
        }
        
        .tickets-container {
            max-height: 200px;
            overflow-y: auto;
            margin-top: 15px;
            background: #0a0a0a;
            border-radius: 8px;
            padding: 10px;
        }
        
        .ticket-item {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .ticket-item:hover {
            transform: translateX(5px);
            border-color: red;
        }
        
        .ticket-number {
            color: red;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .ticket-product {
            color: #ccc;
            font-size: 0.8rem;
        }

        /* Footer Styles */
        .footer {
            background: #111;
            border-top: 2px solid red;
            padding: 60px 20px 20px;
            margin-top: 60px;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 50px;
        }

        .footer-col {
            display: flex;
            flex-direction: column;
            gap: 20px;
            animation: slideUp 0.5s ease forwards;
            animation-delay: calc(0.1s * var(--i));
            opacity: 0;
        }

        .footer-col:nth-child(1) { --i: 1; }
        .footer-col:nth-child(2) { --i: 2; }
        .footer-col:nth-child(3) { --i: 3; }
        .footer-col:nth-child(4) { --i: 4; }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.3s ease;
        }

        .footer-logo:hover {
            transform: scale(1.05);
        }

        .footer-logo img {
            width: 50px;
            height: 50px;
            filter: drop-shadow(0 0 10px rgba(255, 0, 0, 0.5));
            transition: all 0.3s ease;
        }

        .footer-logo:hover img {
            filter: drop-shadow(0 0 20px rgba(255, 0, 0, 0.8));
        }

        .footer-brand {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.5rem;
            background: linear-gradient(90deg, #fff, #ff0000);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .footer-description {
            color: #888;
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 10px;
            transition: color 0.3s ease;
        }

        .footer-description:hover {
            color: #aaa;
        }

        .social-links {
            display: flex;
            gap: 15px;
        }

        .social-link {
            width: 40px;
            height: 40px;
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid rgba(255, 0, 0, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .social-link::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .social-link:hover::before {
            width: 80px;
            height: 80px;
        }

        .social-link:hover {
            background: red;
            transform: translateY(-5px) scale(1.1);
            border-color: red;
        }

        .footer-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.2rem;
            color: red;
            margin-bottom: 10px;
            position: relative;
            padding-bottom: 10px;
        }

        .footer-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 2px;
            background: red;
            transition: width 0.3s ease;
        }

        .footer-col:hover .footer-title::after {
            width: 80px;
        }

        .footer-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .footer-links li a {
            color: #888;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-links li a i {
            font-size: 0.8rem;
            color: red;
            transition: transform 0.3s ease;
        }

        .footer-links li a:hover {
            color: red;
            transform: translateX(5px);
        }

        .footer-links li a:hover i {
            transform: rotate(360deg);
        }

        .footer-contact {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .footer-contact li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #888;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .footer-contact li:hover {
            transform: translateX(5px);
        }

        .footer-contact li i {
            color: red;
            font-size: 1.1rem;
            margin-top: 3px;
            transition: transform 0.3s ease;
        }

        .footer-contact li:hover i {
            transform: scale(1.2);
        }

        .footer-contact li span {
            flex: 1;
            line-height: 1.5;
        }

        /* Newsletter */
        .newsletter {
            text-align: center;
            padding: 40px 0;
            border-top: 1px solid #222;
            border-bottom: 1px solid #222;
            margin-bottom: 30px;
        }

        .newsletter-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.5rem;
            color: red;
            margin-bottom: 10px;
        }

        .newsletter-text {
            color: #888;
            margin-bottom: 20px;
        }

        .newsletter-form {
            display: flex;
            max-width: 500px;
            margin: 0 auto;
            gap: 10px;
        }

        .newsletter-form input {
            flex: 1;
            padding: 15px 20px;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            color: white;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .newsletter-form input:focus {
            outline: none;
            border-color: red;
            transform: scale(1.02);
        }

        .newsletter-form button {
            padding: 15px 30px;
            background: red;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            font-family: 'Orbitron', sans-serif;
            transition: all 0.3s ease;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }

        .newsletter-form button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .newsletter-form button:hover::before {
            width: 400px;
            height: 400px;
        }

        .newsletter-form button:hover {
            background: #cc0000;
            transform: translateY(-2px);
        }

        /* Bottom Bar */
        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            padding-top: 20px;
        }

        .copyright {
            color: #666;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .copyright:hover {
            color: #888;
        }

        .footer-bottom-links {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .footer-bottom-links a {
            color: #888;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
            position: relative;
        }

        .footer-bottom-links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 1px;
            background: red;
            transition: width 0.3s ease;
        }

        .footer-bottom-links a:hover::after {
            width: 100%;
        }

        .footer-bottom-links a:hover {
            color: red;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .nav {
                padding: 15px 30px;
            }
            
            .nav-logo {
                width: 55px;
                height: 55px;
            }
            
            .nav-title {
                font-size: 24px;
            }
            
            .nav-menu {
                gap: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .nav {
                padding: 15px 20px;
            }
            
            .nav-logo {
                width: 45px;
                height: 45px;
            }
            
            .nav-title {
                font-size: 20px;
                letter-spacing: 2px;
            }
            
            .mobile-menu-btn,
            .mobile-search-btn {
                display: block;
            }
            
            .nav-menu {
                position: fixed;
                top: 0;
                right: -100%;
                width: 300px;
                height: 100vh;
                background: rgba(0, 0, 0, 0.98);
                backdrop-filter: blur(15px);
                flex-direction: column;
                padding: 80px 25px 30px;
                transition: right 0.3s ease;
                z-index: 1000;
                border-left: 2px solid red;
                overflow-y: auto;
            }
            
            .nav-menu.active {
                right: 0;
            }
            
            .nav-menu.active li {
                animation: slideInRight 0.3s ease forwards;
                animation-delay: calc(0.05s * var(--i));
            }
            
            .nav-menu.active li:nth-child(1) { --i: 1; }
            .nav-menu.active li:nth-child(2) { --i: 2; }
            .nav-menu.active li:nth-child(3) { --i: 3; }
            .nav-menu.active li:nth-child(4) { --i: 4; }
            .nav-menu.active li:nth-child(5) { --i: 5; }
            .nav-menu.active li:nth-child(6) { --i: 6; }

            @keyframes slideInRight {
                from {
                    opacity: 0;
                    transform: translateX(50px);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }
            
            .nav-menu li {
                margin: 5px 0;
            }
            
            .nav-menu li a {
                padding: 15px 20px;
                font-size: 1.1rem;
            }
            
            .notification-bell {
                margin-right: 0;
            }
            
            .notification-dropdown {
                position: fixed;
                top: 80px;
                left: 10px;
                right: 10px;
                width: auto;
                margin-top: 0;
            }
            
            .dropdown-menu {
                position: static;
                background: rgba(30, 30, 30, 0.95);
                border: 1px solid #444;
                margin: 5px 0 5px 20px;
                display: none;
                min-width: 100%;
            }
            
            .dropdown-menu li a {
                white-space: normal;
            }
            
            .dropdown.active .dropdown-menu {
                display: block;
            }
            
            .close-menu-btn {
                display: block;
            }
            
            .mobile-search-container {
                top: 80px;
            }
            
            .recent-reservations-section {
                margin-top: 100px;
            }
            
            .recent-reservations-header {
                font-size: 1.5rem;
            }
            
            .cart-header {
                font-size: 2rem;
            }
            
            .footer-grid {
                gap: 30px;
            }

            .newsletter-form {
                flex-direction: column;
            }

            .newsletter-form button {
                width: 100%;
            }

            .footer-bottom {
                flex-direction: column;
                text-align: center;
            }

            .footer-bottom-links {
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .nav {
                padding: 12px 15px;
            }
            
            .nav-logo {
                width: 45px;
                height: 45px;
            }
            
            .nav-title {
                font-size: 20px;
                letter-spacing: 2px;
            }
            
            .mobile-search-container {
                top: 70px;
            }
            
            .notification-dropdown {
                top: 70px;
            }
            
            .notification-item {
                flex-direction: column;
                gap: 10px;
            }
            
            .notification-icon {
                width: 30px;
                height: 30px;
                font-size: 1rem;
            }
            
            .recent-reservations-grid {
                grid-template-columns: 1fr;
            }
            
            .cart-item {
                flex-direction: column;
                text-align: center;
            }
            
            .item-price {
                text-align: center;
            }
            
            .item-remove {
                justify-content: center;
            }
            
            .cart-summary {
                flex-direction: column;
                text-align: center;
            }
            
            .footer-title::after {
                left: 50%;
                transform: translateX(-50%);
            }

            .footer-col {
                text-align: center;
                align-items: center;
            }

            .footer-links li a {
                justify-content: center;
            }

            .footer-contact li {
                justify-content: center;
            }

            .social-links {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation with Notification Bell at Bottom of Right Side -->
    <nav class="nav">
        <div class="nav-left">
            <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" class="nav-logo" alt="Ridershub Logo">
            <span class="nav-title">RIDERSHUB</span>
        </div>
        
        <!-- Mobile Menu Button -->
        <div style="display: flex; gap: 10px; align-items: center;">
            <!-- Low Stock Warning for User's Pending Reservations -->
            <?php if($low_stock_count > 0): ?>
            <span class="low-stock-warning" title="Some items in your pending reservations are running low">
                <i class="fas fa-exclamation-triangle"></i> <?= $low_stock_count ?> Low Stock
            </span>
            <?php endif; ?>
            
            <button class="mobile-search-btn" id="mobileSearchBtn">
                <i class="fas fa-search"></i>
            </button>
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <ul class="nav-menu" id="navMenu">
            <!-- Close Button for Mobile -->
            <button class="close-menu-btn" id="closeMenuBtn">
                <i class="fas fa-times"></i>
            </button>
            
            <li><a href="index.php"><i class="fas fa-home"></i> HOME</a></li>
            
            <li class="dropdown">
                <a href="#" class="dropdown-toggle"><i class="fas fa-helmet-safety"></i> PRODUCTS ▾</a>
                <ul class="dropdown-menu">
                    <li><a href="index.php#all"><i class="fas fa-list"></i> ALL PRODUCTS</a></li>
                    <?php
                    $nav_brands = $conn->query("SELECT * FROM brands ORDER BY brand_name ASC");
                    if($nav_brands && $nav_brands->num_rows > 0):
                        while($row = $nav_brands->fetch_assoc()): 
                            $b_name = $row['brand_name'];
                            $b_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $b_name));
                    ?>
                        <li><a href="index.php?brand=<?= $b_slug ?>#products"><i class="fas fa-tag"></i> <?= strtoupper($b_name) ?></a></li>
                    <?php 
                        endwhile;
                    endif; 
                    ?>
                </ul>
            </li>
            
            <li class="dropdown">
                <a href="#" class="dropdown-toggle"><i class="fas fa-tag"></i> HELMET TYPE ▾</a>
                <ul class="dropdown-menu">
                    <li><a href="index.php?type=all#products"><i class="fas fa-list"></i> ALL TYPES</a></li>
                    <li><a href="index.php?type=full-face#products"><i class="fas fa-helmet-safety"></i> FULL FACE</a></li>
                    <li><a href="index.php?type=modular#products"><i class="fas fa-helmet-safety"></i> MODULAR</a></li>
                    <li><a href="index.php?type=half-face#products"><i class="fas fa-helmet-safety"></i> HALF FACE</a></li>
                    <li><a href="index.php?type=open-face#products"><i class="fas fa-helmet-safety"></i> OPEN FACE</a></li>
                    <li><a href="index.php?type=off-road#products"><i class="fas fa-helmet-safety"></i> OFF ROAD</a></li>
                </ul>
            </li>
            
            <li class="active"><a href="cart.php"><i class="fas fa-shopping-cart"></i> CART <span class="cart-count" id="navCartCount"><?= $cart_count ?></span></a></li>
            
            <!-- Notification Bell - Placed right before Logout -->
            <li style="position: relative;">
                <div class="notification-container">
                    <div class="notification-bell" id="notificationBell">
                        <i class="fas fa-bell"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="notification-badge" id="notificationBadge"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Notification Dropdown -->
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header">
                            <h3><i class="fas fa-bell"></i> NOTIFICATIONS</h3>
                            <?php if($unread_count > 0): ?>
                                <a href="?mark_all_read=1" class="mark-all-read">
                                    <i class="fas fa-check-double"></i> Mark all as read
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notification-list">
                            <?php if($notifications_result && mysqli_num_rows($notifications_result) > 0): ?>
                                <?php while($notif = mysqli_fetch_assoc($notifications_result)): ?>
                                    <a href="?mark_read=<?= $notif['id'] ?>" style="text-decoration: none;">
                                        <div class="notification-item <?= $notif['is_read'] == 0 ? 'unread' : '' ?>">
                                            <div class="notification-icon">
                                                <?php 
                                                if($notif['type'] == 'confirmed'): 
                                                    echo '<i class="fas fa-check-circle"></i>';
                                                elseif($notif['type'] == 'cancel' || $notif['type'] == 'cancelled'): 
                                                    echo '<i class="fas fa-times-circle"></i>';
                                                elseif($notif['type'] == 'completed'): 
                                                    echo '<i class="fas fa-flag-checkered"></i>';
                                                elseif($notif['type'] == 'pending'): 
                                                    echo '<i class="fas fa-clock"></i>';
                                                else: 
                                                    echo '<i class="fas fa-bell"></i>';
                                                endif; 
                                                ?>
                                            </div>
                                            <div class="notification-content">
                                                <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                                <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                                                <div class="notification-time">
                                                    <i class="far fa-clock"></i> <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?>
                                                    <?php if($notif['reservation_code']): ?>
                                                        <span class="notification-status">
                                                            <i class="fas fa-ticket-alt"></i> <?= $notif['reservation_code'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="notification-empty">
                                    <i class="fas fa-bell-slash"></i>
                                    <h3>No Notifications</h3>
                                    <p>You're all caught up!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </li>
            
            <li><a href="logout.php" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt"></i> LOGOUT</a></li>
        </ul>
    </nav>

    <!-- Mobile Search Container -->
    <div class="mobile-search-container" id="mobileSearchContainer">
        <input type="text" class="mobile-search-input" placeholder="Search products..." id="mobileSearchInput">
    </div>
    
    <!-- RECENT RESERVATIONS SECTION -->
    <div class="recent-reservations-section">
        <h2 class="recent-reservations-header">
            <i class="fas fa-clock-rotate-left"></i> YOUR RECENT RESERVATIONS
            <?php if($low_stock_count > 0): ?>
            <span class="low-stock-warning">
                <i class="fas fa-exclamation-triangle"></i> <?= $low_stock_count ?> items low stock
            </span>
            <?php endif; ?>
        </h2>
        
        <!-- Display Messages -->
        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <?= $_SESSION['message']; unset($_SESSION['message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> 
                <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php if($recent_reservations_result && mysqli_num_rows($recent_reservations_result) > 0): ?>
            <div class="recent-reservations-grid">
                <?php while($recent = mysqli_fetch_assoc($recent_reservations_result)): ?>
                    <?php 
                    $color = $recent['selected_color'] ?? 'Standard';
                    $size = $recent['selected_size'] ?? 'One Size';
                    $total_amount = $recent['total_amount'] ?? ($recent['price'] * $recent['quantity']);
                    $status = $recent['status'] ?? 'pending';
                    $current_stock = $recent['current_stock'] ?? 0;
                    $current_reserved = $recent['current_reserved'] ?? 0;
                    $available_stock = $current_stock - $current_reserved;
                    ?>
                    <div class="recent-reservation-card">
                        <div class="recent-reservation-content">
                            <?php if($recent['product_image']): ?>
                                <img src="uploads/<?= $recent['product_image'] ?>" 
                                     alt="<?= htmlspecialchars($recent['product_name']) ?>" 
                                     class="recent-reservation-image">
                            <?php else: ?>
                                <div class="recent-reservation-image" style="background: #1a1a1a; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-helmet-safety" style="color: #666; font-size: 2rem;"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="recent-reservation-details">
                                <div class="recent-reservation-name">
                                    <?= htmlspecialchars($recent['product_name'] ?? 'Unknown Product') ?>
                                </div>
                                <div class="recent-reservation-brand">
                                    <?= htmlspecialchars($recent['brand_name'] ?? 'No Brand') ?>
                                </div>
                                
                                <div class="recent-reservation-specs">
                                    <span>Color:</span> <?= htmlspecialchars($color) ?><br>
                                    <span>Size:</span> <?= htmlspecialchars($size) ?><br>
                                    <span>Qty:</span> <?= $recent['quantity'] ?>
                                    <?php if($status == 'PENDING'): ?>
                                        <span class="stock-info-small">
                                            <i class="fas fa-box"></i> Available: <?= max(0, $available_stock) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="margin-top: 8px;">
                                    <span class="status-badge status-<?= strtolower($status) ?>">
                                        <?= strtoupper($status) ?>
                                    </span>
                                    <span class="recent-reservation-code">
                                        <i class="fas fa-ticket-alt"></i> <?= $recent['ticket_number'] ?? $recent['reservation_code'] ?? 'N/A' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="recent-reservation-footer">
                            <span class="recent-reservation-price">
                                ₱<?= number_format($total_amount, 2) ?>
                            </span>
                            <div class="reservation-actions">
                                <?php if($status != 'CANCELLED' && $status != 'COMPLETED'): ?>
                                <button class="reserve-again-btn" onclick='openReserveAgainModal(<?= json_encode([
                                    'product_id' => $recent['product_id'],
                                    'name' => $recent['product_name'],
                                    'color' => $color,
                                    'size' => $size,
                                    'quantity' => (int)$recent['quantity'],
                                    'image' => $recent['product_image'] ?? ''
                                ]) ?>)'>
                                    <i class="fas fa-rotate-right"></i> RESERVE AGAIN
                                </button>
                                <?php endif; ?>
                                <?php if($status == 'PENDING' || $status == 'CANCELLED'): ?>
                                <button class="delete-reservation-btn" onclick="deleteReservation(<?= $recent['id'] ?>, '<?= addslashes($recent['product_name'] ?? 'this item') ?>')">
                                    <i class="fas fa-trash"></i> DELETE
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="recent-empty">
                <i class="fas fa-calendar-check"></i>
                <h3>No Recent Reservations</h3>
                <p>Your reservation history will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="cart-container">
        <h1 class="cart-header">
            <i class="fas fa-shopping-cart"></i> YOUR CART
            <?php if($cart_count > 0): ?>
            <span class="cart-count" style="position: static; animation: none;"><?= $cart_count ?> items</span>
            <?php endif; ?>
        </h1>
        
        <?php if(empty($cart_items)): ?>
            <div class="cart-items">
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart"></i>
                    <h2>Your cart is empty</h2>
                    <p>Looks like you haven't added any items to your cart yet.</p>
                    <a href="index.php#products" class="continue-shopping">
                        <i class="fas fa-arrow-left"></i> CONTINUE SHOPPING
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="cart-items">
                <?php foreach($cart_items as $key => $item): ?>
                    <?php 
                    $available_stock = $item['available_stock'] ?? 0;
                    $stock_class = '';
                    $stock_message = '';
                    if($available_stock <= 0) {
                        $stock_class = 'out-of-stock';
                        $stock_message = 'Out of Stock';
                    } elseif($available_stock <= 5) {
                        $stock_class = 'low-stock';
                        $stock_message = 'Low Stock';
                    }
                    ?>
                    <div class="cart-item" data-cart-key="<?= $key ?>" data-product-id="<?= $item['product_id'] ?>">
                        <img src="uploads/<?= $item['image'] ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                        <div class="item-details">
                            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="item-specs">
                                <span style="color: #fff;">Color:</span> <?= htmlspecialchars($item['color']) ?><br>
                                <span style="color: #fff;">Size:</span> <?= htmlspecialchars($item['size']) ?><br>
                                <span class="stock-info-small <?= $stock_class ?>">
                                    <i class="fas fa-<?= $available_stock > 0 ? 'check-circle' : 'exclamation-circle' ?>"></i>
                                    Available: <?= $available_stock ?> <?= $stock_message ? "($stock_message)" : '' ?>
                                </span>
                            </div>
                        </div>
                        <div class="item-price">
                            ₱<?= number_format($item['price'], 2) ?>
                        </div>
                        <div class="item-quantity">
                            <div class="quantity-control">
                                <button class="quantity-btn" onclick="updateQuantity('<?= $key ?>', -1)" 
                                    <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>-</button>
                                <input type="number" class="quantity-input" value="<?= $item['quantity'] ?>" min="1" max="<?= $available_stock ?>" readonly>
                                <button class="quantity-btn" onclick="updateQuantity('<?= $key ?>', 1)" 
                                    <?= $item['quantity'] >= $available_stock ? 'disabled' : '' ?>>+</button>
                            </div>
                        </div>
                        <div class="item-remove">
                            <button class="remove-btn" onclick="removeItem('<?= $key ?>')" title="Remove from cart">
                                <i class="fas fa-trash"></i>
                            </button>
                            <button class="reserve-item-btn" onclick="reserveSingleItemFromCart('<?= $key ?>')"
                                <?= $available_stock <= 0 ? 'disabled' : '' ?>>
                                <i class="fas fa-check-circle"></i> RESERVE
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="cart-summary">
                    <div class="cart-total">
                        TOTAL: <span class="total-amount">₱<?= number_format($cart_total, 2) ?></span>
                    </div>
                    <button class="reserve-btn" onclick="openReserveAllModal()" 
                        <?= $cart_count == 0 ? 'disabled' : '' ?>>
                        RESERVE ALL ITEMS <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- SINGLE ITEM RESERVATION MODAL (for cart items) -->
    <div id="reserveModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 style="font-family: 'Audiowide'; color: white;">RESERVATION</h2>
            <p id="modalProductName" style="color: red; font-size: 14px; margin-bottom: 5px;"></p>
            <div id="modalStockDisplay" class="stock-tag">Available Stock: 0</div> 
            <form id="reservationForm">
                <input type="hidden" name="product_id" id="modalProductId">
                <input type="hidden" name="cart_key" id="modalCartKey">
                <input type="hidden" name="action" value="reserve_from_cart">
                
                <label>FULL NAME</label>
                <input type="text" name="customer_name" id="customerName" required placeholder="Juan Dela Cruz" value="<?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>">
                
                <label>CONTACT NUMBER</label>
                <input type="tel" 
                       name="phone" 
                       id="customerPhone" 
                       required 
                       placeholder="09123456789" 
                       value="<?= htmlspecialchars($_SESSION['phone'] ?? '') ?>"
                       pattern="[0-9]{11}"
                       inputmode="numeric"
                       maxlength="11"
                       onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">

                <div class="selection-row">
                    <div>
                        <label>COLOR</label>
                        <select name="selected_color" id="modalColor" required></select>
                    </div>
                    <div>
                        <label>SIZE</label>
                        <select name="selected_size" id="modalSize" required></select>
                    </div>
                </div>

                <label>QUANTITY</label>
                <input type="number" name="quantity" id="modalQuantity" min="1" value="1" required>

                <label>TARGET PICKUP DATE</label>
                <input type="date" name="pickup_date" id="pickupDate" required>
                
                <button type="submit" class="submit-res-btn">CONFIRM RESERVATION</button>
            </form>
        </div>
    </div>
    
    <!-- RESERVE AGAIN MODAL (for recent reservations) -->
    <div id="reserveAgainModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeReserveAgainModal()">&times;</span>
            <h2 style="font-family: 'Audiowide'; color: white;">RESERVE AGAIN</h2>
            <p id="reserveAgainProductName" style="color: red; font-size: 14px; margin-bottom: 5px;"></p>
            <div id="reserveAgainStockDisplay" class="stock-tag">Available Stock: 0</div> 
            <form id="reserveAgainForm">
                <input type="hidden" name="product_id" id="reserveAgainProductId">
                <input type="hidden" name="action" value="reserve_from_cart">
                
                <label>FULL NAME</label>
                <input type="text" name="customer_name" id="reserveAgainCustomerName" required placeholder="Juan Dela Cruz" value="<?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>">
                
                <label>CONTACT NUMBER</label>
                <input type="tel" 
                       name="phone" 
                       id="reserveAgainCustomerPhone" 
                       required 
                       placeholder="09123456789" 
                       value="<?= htmlspecialchars($_SESSION['phone'] ?? '') ?>"
                       pattern="[0-9]{11}"
                       inputmode="numeric"
                       maxlength="11"
                       onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">

                <div class="selection-row">
                    <div>
                        <label>COLOR</label>
                        <select name="selected_color" id="reserveAgainColor" required></select>
                    </div>
                    <div>
                        <label>SIZE</label>
                        <select name="selected_size" id="reserveAgainSize" required></select>
                    </div>
                </div>

                <label>QUANTITY</label>
                <input type="number" name="quantity" id="reserveAgainQuantity" min="1" value="1" required>

                <label>TARGET PICKUP DATE</label>
                <input type="date" name="pickup_date" id="reserveAgainPickupDate" required>
                
                <button type="submit" class="submit-res-btn">CONFIRM RESERVATION</button>
            </form>
        </div>
    </div>
    
    <!-- RESERVE ALL ITEMS MODAL -->
    <div id="reserveAllModal" class="modal reserve-all-modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeReserveAllModal()">&times;</span>
            <h2><i class="fas fa-shopping-cart"></i> RESERVE ALL ITEMS</h2>
            
            <div style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                <!-- Cart Items List -->
                <div class="cart-items-list" id="reserveAllItemsList"></div>
                
                <!-- Customer Details -->
                <div class="customer-details">
                    <h3>CUSTOMER INFORMATION</h3>
                    <input type="text" id="reserveAllCustomerName" placeholder="Full Name" value="<?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>">
                    <input type="tel" 
                           id="reserveAllCustomerPhone" 
                           placeholder="Contact Number" 
                           value="<?= htmlspecialchars($_SESSION['phone'] ?? '') ?>"
                           pattern="[0-9]{11}"
                           inputmode="numeric"
                           maxlength="11"
                           onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">
                </div>
                
                <!-- Pickup Date -->
                <div style="margin-bottom: 20px;">
                    <label style="color: #ccc; display: block; margin-bottom: 5px;">TARGET PICKUP DATE</label>
                    <input type="date" id="reserveAllPickupDate" class="pickup-date-input" required>
                </div>
                
                <!-- Summary -->
                <div class="reservation-summary">
                    <div class="reservation-summary-row">
                        <span class="reservation-summary-label">Total Items:</span>
                        <span class="reservation-summary-value" id="totalItemsCount"><?= $cart_count ?></span>
                    </div>
                    <div class="reservation-summary-row">
                        <span class="reservation-summary-label">Total Amount:</span>
                        <span class="reservation-summary-value" id="totalAmountDisplay">₱<?= number_format($cart_total, 2) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="modal-actions">
                <button class="modal-btn modal-btn-secondary" onclick="closeReserveAllModal()">CANCEL</button>
                <button class="modal-btn modal-btn-primary" onclick="processReserveAllItems()">CONFIRM RESERVE ALL</button>
            </div>
        </div>
    </div>

    <script>
        // =========== CART ITEMS DATA ===========
        const cartItemsData = <?= json_encode($js_cart_items) ?>;
        let cartItems = [...cartItemsData];
        
        // =========== NOTIFICATION BELL FUNCTIONALITY ===========
        document.addEventListener("DOMContentLoaded", function() {
            const notificationBell = document.getElementById("notificationBell");
            const notificationDropdown = document.getElementById("notificationDropdown");
            
            // Toggle notification dropdown
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.style.display = notificationDropdown.style.display === 'block' ? 'none' : 'block';
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationBell.contains(e.target) && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.style.display = 'none';
                }
            });
            
            // Prevent dropdown from closing when clicking inside
            notificationDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            
            // Check for new notifications every 30 seconds
            setInterval(function() {
                location.reload();
            }, 30000);
        });
        
        // =========== MOBILE MENU FUNCTIONALITY ===========
        document.addEventListener("DOMContentLoaded", function() {
            const mobileMenuBtn = document.getElementById("mobileMenuBtn");
            const navMenu = document.getElementById("navMenu");
            const closeMenuBtn = document.getElementById("closeMenuBtn");
            const mobileSearchBtn = document.getElementById("mobileSearchBtn");
            const mobileSearchContainer = document.getElementById("mobileSearchContainer");
            const mobileSearchInput = document.getElementById("mobileSearchInput");
            const nav = document.querySelector('.nav');
            
            // Navbar scroll effect
            window.addEventListener('scroll', function() {
                if (window.scrollY > 100) {
                    nav.classList.add('scrolled');
                } else {
                    nav.classList.remove('scrolled');
                }
            });
            
            // Mobile Menu Toggle
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', () => {
                    navMenu.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
            }
            
            if (closeMenuBtn) {
                closeMenuBtn.addEventListener('click', () => {
                    navMenu.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }
            
            // Close menu when clicking outside on mobile
            document.addEventListener('click', (e) => {
                if (window.innerWidth <= 768) {
                    if (navMenu && !navMenu.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                        navMenu.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                }
            });
            
            // Mobile dropdown toggle
            document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                toggle.addEventListener('click', (e) => {
                    if (window.innerWidth <= 768) {
                        e.preventDefault();
                        const dropdown = toggle.closest('.dropdown');
                        dropdown.classList.toggle('active');
                    }
                });
            });
            
            // Mobile Search Toggle
            if (mobileSearchBtn) {
                mobileSearchBtn.addEventListener('click', () => {
                    if (mobileSearchContainer) {
                        mobileSearchContainer.style.display = mobileSearchContainer.style.display === 'block' ? 'none' : 'block';
                        if (mobileSearchContainer.style.display === 'block') {
                            mobileSearchInput.focus();
                        }
                    }
                });
            }
            
            // Mobile Search Functionality
            if (mobileSearchInput) {
                mobileSearchInput.addEventListener('input', (e) => {
                    const searchTerm = e.target.value.toLowerCase().trim();
                    
                    // This would redirect to index page with search parameter
                    if (searchTerm.length > 2) {
                        window.location.href = 'index.php?search=' + encodeURIComponent(searchTerm);
                    }
                });
            }
            
            // Responsive adjustments on window resize
            window.addEventListener('resize', () => {
                if (window.innerWidth > 768) {
                    if (navMenu) navMenu.classList.remove('active');
                    document.body.style.overflow = '';
                    if (mobileSearchContainer) mobileSearchContainer.style.display = 'none';
                    
                    // Reset dropdowns
                    document.querySelectorAll('.dropdown').forEach(d => d.classList.remove('active'));
                }
            });
        });
        
        // =========== UPDATE QUANTITY ===========
        function updateQuantity(cartKey, change) {
            const cartItem = document.querySelector(`.cart-item[data-cart-key="${cartKey}"]`);
            const quantityInput = cartItem.querySelector('.quantity-input');
            const currentQty = parseInt(quantityInput.value);
            const maxQty = parseInt(quantityInput.max);
            const newQty = currentQty + change;
            
            if (newQty < 1 || newQty > maxQty) return;
            
            fetch('cart_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update_quantity&cart_key=' + encodeURIComponent(cartKey) + '&change=' + change
            })
            .then(response => response.json())
            .then(data => { 
                if(data.success) location.reload(); 
            })
            .catch(error => console.error('Error:', error));
        }
        
        // =========== REMOVE ITEM ===========
        function removeItem(cartKey) {
            Swal.fire({
                title: 'Remove Item?',
                text: 'Are you sure you want to remove this item from your cart?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff0000',
                cancelButtonColor: '#666',
                confirmButtonText: 'Yes, remove it!',
                background: '#111',
                color: '#fff'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('cart_handler.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=remove_item&cart_key=' + encodeURIComponent(cartKey)
                    })
                    .then(response => response.json())
                    .then(data => { if(data.success) location.reload(); });
                }
            });
        }

        // =========== UPDATE CART COUNT ===========
        function updateCartCount(quantity) {
            const cartNavLink = document.getElementById('cartNavLink');
            if (cartNavLink) {
                const currentText = cartNavLink.innerText;
                const currentCount = parseInt(currentText.match(/\d+/) || 0);
                const newCount = Math.max(0, currentCount - quantity);
                cartNavLink.innerText = `CART (${newCount})`;
            }
            
            // Also update the cart count badge
            const navCartCount = document.getElementById('navCartCount');
            if (navCartCount) {
                const currentCount = parseInt(navCartCount.innerText) || 0;
                const newCount = Math.max(0, currentCount - quantity);
                navCartCount.innerText = newCount;
            }
        }

        // =========== OPEN RESERVE AGAIN MODAL ===========
        function openReserveAgainModal(itemData) {
            const modal = document.getElementById("reserveAgainModal");
            
            document.getElementById("reserveAgainProductName").innerText = "Item: " + itemData.name;
            document.getElementById("reserveAgainProductId").value = itemData.product_id;
            document.getElementById("reserveAgainStockDisplay").innerText = "Checking stock...";
            
            // Pre-fill with session data
            document.getElementById("reserveAgainCustomerName").value = '<?= $_SESSION['fullname'] ?? '' ?>';
            document.getElementById("reserveAgainCustomerPhone").value = '<?= $_SESSION['phone'] ?? '' ?>';
            
            // Set default pickup date to tomorrow
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById("reserveAgainPickupDate").value = tomorrow.toISOString().split('T')[0];
            document.getElementById("reserveAgainPickupDate").min = tomorrow.toISOString().split('T')[0];
            
            // Fetch product details for stock and options
            fetch('cart_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_product_details&id=' + itemData.product_id
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    let stockCount = data.stock || 0;
                    let reservedStock = data.reserved_stock || 0;
                    
                    document.getElementById("reserveAgainStockDisplay").innerText = "Available Stock: " + stockCount;
                    if(reservedStock > 0) {
                        document.getElementById("reserveAgainStockDisplay").innerHTML += `<br><small style="color: #ffaa00;">(${reservedStock} already reserved)</small>`;
                    }
                    
                    // Populate Colors
                    const colorSelect = document.getElementById("reserveAgainColor");
                    colorSelect.innerHTML = '';
                    let colorArray = [];
                    if (data.colors && typeof data.colors === 'string') {
                        if (data.colors.includes(',')) colorArray = data.colors.split(',').map(c => c.trim()).filter(c => c !== '');
                        else colorArray = [data.colors];
                    } else colorArray = [itemData.color];
                    
                    colorArray.forEach(color => {
                        let option = document.createElement('option');
                        option.value = color;
                        option.textContent = color;
                        if(color === itemData.color) option.selected = true;
                        colorSelect.appendChild(option);
                    });
                    
                    // Populate Sizes
                    const sizeSelect = document.getElementById("reserveAgainSize");
                    sizeSelect.innerHTML = '';
                    let sizeArray = [];
                    if (data.sizes && typeof data.sizes === 'string') {
                        if (data.sizes.includes(',')) sizeArray = data.sizes.split(',').map(s => s.trim()).filter(s => s !== '');
                        else sizeArray = [data.sizes];
                    } else sizeArray = [itemData.size];
                    
                    sizeArray.forEach(size => {
                        let option = document.createElement('option');
                        option.value = size;
                        option.textContent = size;
                        if(size === itemData.size) option.selected = true;
                        sizeSelect.appendChild(option);
                    });
                    
                    // Set quantity
                    const qtyInput = document.getElementById("reserveAgainQuantity");
                    qtyInput.max = stockCount || 999;
                    qtyInput.value = Math.min(itemData.quantity, qtyInput.max);
                } else {
                    document.getElementById("reserveAgainStockDisplay").innerText = "Stock information unavailable";
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById("reserveAgainStockDisplay").innerText = "Stock information unavailable";
            });
            
            modal.style.display = "block";
            document.body.style.overflow = 'hidden';
        }

        function closeReserveAgainModal() {
            document.getElementById("reserveAgainModal").style.display = "none";
            document.body.style.overflow = '';
        }

        // =========== DELETE RESERVATION FUNCTION ===========
        function deleteReservation(reservationId, productName) {
            Swal.fire({
                title: 'Delete Reservation?',
                html: `Are you sure you want to delete your reservation for <strong style="color: red;">${productName}</strong>?<br><br>
                       <span style="color: #ffc107; font-size: 0.9rem;">This action cannot be undone and the reservation will be permanently removed.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#666',
                confirmButtonText: 'Yes, delete permanently',
                cancelButtonText: 'Cancel',
                background: '#111',
                color: '#fff',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Deleting...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading(),
                        background: '#111',
                        color: '#fff'
                    });
                    
                    // Redirect to delete action
                    window.location.href = 'cart.php?delete_reservation=' + reservationId;
                }
            });
        }

        // =========== SINGLE ITEM RESERVATION (from cart) ===========
        function reserveSingleItemFromCart(cartKey) {
            const cartItem = document.querySelector(`.cart-item[data-cart-key="${cartKey}"]`);
            if (!cartItem) return;
            
            const productId = cartItem.dataset.productId;
            const productName = cartItem.querySelector('.item-name').innerText;
            const specsHTML = cartItem.querySelector('.item-specs').innerHTML;
            
            let color = 'Standard';
            const colorMatch = specsHTML.match(/Color:<\/span>\s*(.*?)<br>/);
            if (colorMatch && colorMatch[1]) color = colorMatch[1].trim();
            
            let size = 'One Size';
            const sizeMatch = specsHTML.match(/Size:<\/span>\s*(.*?)</);
            if (sizeMatch && sizeMatch[1]) size = sizeMatch[1].trim();
            
            const quantity = parseInt(cartItem.querySelector('.quantity-input').value);
            
            fetchProductDetails(productId, cartKey, productName, color, size, quantity);
        }

        function fetchProductDetails(productId, cartKey, productName, currentColor, currentSize, currentQuantity) {
            Swal.fire({
                title: 'Loading...',
                text: 'Fetching product details',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
                background: '#111',
                color: '#fff'
            });
            
            fetch('cart_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_product_details&id=' + productId
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
                if(data.success) {
                    let stockCount = data.stock || 0;
                    let totalStock = data.total_stock || 0;
                    let reservedStock = data.reserved_stock || 0;
                    
                    // Show stock info with reservation details
                    let stockInfo = `Available: ${stockCount}`;
                    if(reservedStock > 0) {
                        stockInfo += ` (${reservedStock} already reserved)`;
                    }
                    
                    openReserveModal(cartKey, productId, productName, currentColor, currentSize, 
                                   currentQuantity, stockCount, data.colors, data.sizes, stockInfo);
                } else {
                    // Fallback
                    openReserveModal(cartKey, productId, productName, currentColor, currentSize, 
                                   currentQuantity, 999, currentColor, currentSize, "Stock info unavailable");
                }
            })
            .catch(error => {
                Swal.close();
                openReserveModal(cartKey, productId, productName, currentColor, currentSize, 
                               currentQuantity, 999, currentColor, currentSize, "Stock info unavailable");
            });
        }

        function openReserveModal(cartKey, productId, productName, currentColor, currentSize, 
                                  currentQuantity, stockCount, colors, sizes, stockInfo) {
            const modal = document.getElementById("reserveModal");
            
            document.getElementById("modalProductName").innerText = "Item: " + productName;
            document.getElementById("modalProductId").value = productId;
            document.getElementById("modalCartKey").value = cartKey;
            
            // Show stock info with reservation details
            const stockDisplay = document.getElementById("modalStockDisplay");
            stockDisplay.innerText = "Available Stock: " + stockCount;
            if(stockInfo && stockInfo.includes('reserved')) {
                stockDisplay.innerHTML += `<br><small style="color: #ffaa00;">${stockInfo}</small>`;
            }
            
            if(stockCount <= 0) {
                stockDisplay.classList.add('out-of-stock');
            } else if(stockCount <= 5) {
                stockDisplay.classList.add('low-stock');
            }
            
            // Populate Colors
            const modalColor = document.getElementById("modalColor");
            modalColor.innerHTML = '';
            let colorArray = [];
            if (colors && typeof colors === 'string') {
                if (colors.includes(',')) colorArray = colors.split(',').map(c => c.trim()).filter(c => c !== '');
                else colorArray = [colors];
            } else colorArray = [currentColor];
            
            if (colorArray.length === 0) colorArray = [currentColor];
            
            colorArray.forEach(color => {
                let option = document.createElement('option');
                option.value = color;
                option.textContent = color;
                if(color === currentColor) option.selected = true;
                modalColor.appendChild(option);
            });
            
            // Populate Sizes
            const modalSize = document.getElementById("modalSize");
            modalSize.innerHTML = '';
            let sizeArray = [];
            if (sizes && typeof sizes === 'string') {
                if (sizes.includes(',')) sizeArray = sizes.split(',').map(s => s.trim()).filter(s => s !== '');
                else sizeArray = [sizes];
            } else sizeArray = [currentSize];
            
            if (sizeArray.length === 0) sizeArray = [currentSize];
            
            sizeArray.forEach(size => {
                let option = document.createElement('option');
                option.value = size;
                option.textContent = size;
                if(size === currentSize) option.selected = true;
                modalSize.appendChild(option);
            });
            
            // Set quantity
            const qtyInput = document.getElementById("modalQuantity");
            qtyInput.max = stockCount > 0 ? stockCount : 999;
            qtyInput.value = Math.min(currentQuantity, qtyInput.max);
            
            // Set default pickup date
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById("pickupDate").value = tomorrow.toISOString().split('T')[0];
            document.getElementById("pickupDate").min = tomorrow.toISOString().split('T')[0];
            
            modal.style.display = "block";
            document.body.style.overflow = 'hidden';
        }

        // =========== RESERVE ALL ITEMS ===========
        function openReserveAllModal() {
            <?php if(count($cart_items) > 0): ?>
                const modal = document.getElementById("reserveAllModal");
                const itemsList = document.getElementById("reserveAllItemsList");
                itemsList.innerHTML = '';
                
                let hasUnavailable = false;
                
                cartItems.forEach(item => {
                    const isAvailable = item.available_stock > 0;
                    if(!isAvailable) hasUnavailable = true;
                    
                    const itemHtml = `
                        <div class="cart-item-preview" style="${!isAvailable ? 'opacity: 0.5;' : ''}">
                            <img src="uploads/${item.image}" alt="${item.name}">
                            <div class="cart-item-preview-details">
                                <div class="cart-item-preview-name">${item.name}</div>
                                <div class="cart-item-preview-specs">
                                    Color: ${item.color} | Size: ${item.size}
                                </div>
                                <div class="cart-item-preview-price">₱${item.price.toLocaleString()}</div>
                                ${!isAvailable ? '<div class="cart-item-preview-stock"><i class="fas fa-exclamation-circle"></i> Out of Stock</div>' : ''}
                                ${item.available_stock <= 5 && isAvailable ? '<div class="cart-item-preview-stock"><i class="fas fa-exclamation-triangle"></i> Low Stock</div>' : ''}
                            </div>
                            <div class="cart-item-preview-quantity">x${item.quantity}</div>
                        </div>
                    `;
                    itemsList.innerHTML += itemHtml;
                });
                
                document.getElementById("totalItemsCount").innerText = '<?= $cart_count ?>';
                document.getElementById("totalAmountDisplay").innerText = '₱<?= number_format($cart_total, 2) ?>';
                
                const today = new Date();
                const tomorrow = new Date(today);
                tomorrow.setDate(tomorrow.getDate() + 1);
                document.getElementById("reserveAllPickupDate").value = tomorrow.toISOString().split('T')[0];
                document.getElementById("reserveAllPickupDate").min = tomorrow.toISOString().split('T')[0];
                
                modal.style.display = "block";
                document.body.style.overflow = 'hidden';
                
                if(hasUnavailable) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Some items unavailable',
                        text: 'Some items in your cart are out of stock. They will be skipped.',
                        background: '#111',
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                }
            <?php else: ?>
                Swal.fire({
                    title: 'Cart is Empty',
                    text: 'You have no items to reserve.',
                    icon: 'info',
                    background: '#111',
                    color: '#fff',
                    confirmButtonColor: '#ff0000'
                });
            <?php endif; ?>
        }

        function closeReserveAllModal() {
            document.getElementById("reserveAllModal").style.display = "none";
            document.body.style.overflow = '';
        }

        function processReserveAllItems() {
            const customerName = document.getElementById('reserveAllCustomerName').value.trim();
            const customerPhone = document.getElementById('reserveAllCustomerPhone').value.trim();
            const pickupDate = document.getElementById('reserveAllPickupDate').value;
            
            // Validate
            if (!customerName) {
                Swal.fire({ icon: 'error', title: 'Required Field', text: 'Please enter your full name.', background: '#111', color: '#fff', confirmButtonColor: '#ff0000' });
                return;
            }
            if (!customerPhone) {
                Swal.fire({ icon: 'error', title: 'Required Field', text: 'Please enter your contact number.', background: '#111', color: '#fff', confirmButtonColor: '#ff0000' });
                return;
            }
            if (customerPhone.length !== 11) {
                Swal.fire({ icon: 'error', title: 'Invalid Phone Number', text: 'Contact number must be 11 digits.', background: '#111', color: '#fff', confirmButtonColor: '#ff0000' });
                return;
            }
            if (!pickupDate) {
                Swal.fire({ icon: 'error', title: 'Required Field', text: 'Please select a pickup date.', background: '#111', color: '#fff', confirmButtonColor: '#ff0000' });
                return;
            }
            
            closeReserveAllModal();
            
            Swal.fire({
                title: 'Processing Reservations...',
                html: `Reserving ${cartItems.length} item(s)...`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
                background: '#111',
                color: '#fff'
            });
            
            const formData = new FormData();
            formData.append('action', 'reserve_all_items');
            formData.append('customer_name', customerName);
            formData.append('phone', customerPhone);
            formData.append('pickup_date', pickupDate);
            
            fetch('cart_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    let ticketsHtml = '';
                    if (data.tickets && data.tickets.length > 0) {
                        ticketsHtml = '<div class="tickets-container">';
                        data.tickets.forEach((ticket, index) => {
                            ticketsHtml += `
                                <div class="ticket-item">
                                    <span class="ticket-number">${ticket}</span>
                                    <span class="ticket-product">${cartItems[index]?.name || ''}</span>
                                </div>
                            `;
                        });
                        ticketsHtml += '</div>';
                    }
                    
                    let message = `
                        <div style="text-align: left;">
                            <p style="color: #0f0; margin-bottom: 15px;">✅ ${data.success_count} out of ${cartItems.length} items reserved successfully!</p>
                            ${data.failed_count > 0 ? `<p style="color: red; margin-bottom: 10px;">❌ Failed items: ${data.failed_count}</p>` : ''}
                            <p style="color: #ffc107; margin-bottom: 10px;">📋 Generated Tickets:</p>
                            ${ticketsHtml}
                        </div>
                    `;
                    
                    Swal.fire({
                        title: data.failed_count === 0 ? '🎉 ALL ITEMS RESERVED!' : '⚠️ RESERVATION COMPLETED',
                        html: message,
                        icon: data.failed_count === 0 ? 'success' : 'warning',
                        background: '#111',
                        color: '#fff',
                        confirmButtonColor: '#ff0000',
                        confirmButtonText: 'OK',
                        width: '600px'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Reservation Failed',
                        text: data.message || 'Something went wrong!',
                        background: '#111',
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Network error. Please try again.',
                    background: '#111',
                    color: '#fff',
                    confirmButtonColor: '#ff0000'
                });
            });
        }

        // =========== DOM CONTENT LOADED ===========
        document.addEventListener("DOMContentLoaded", function() {
            // Regular reservation modal
            const modal = document.getElementById("reserveModal");
            const closeModal = document.querySelectorAll(".close-modal");
            const reservationForm = document.getElementById("reservationForm");
            
            // Reserve again modal
            const reserveAgainForm = document.getElementById("reserveAgainForm");
            
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            const tomorrowStr = tomorrow.toISOString().split('T')[0];
            
            const pickupDate = document.getElementById('pickupDate');
            const reserveAgainPickupDate = document.getElementById('reserveAgainPickupDate');
            
            if(pickupDate) {
                pickupDate.min = tomorrowStr;
                pickupDate.value = tomorrowStr;
            }
            if(reserveAgainPickupDate) {
                reserveAgainPickupDate.min = tomorrowStr;
                reserveAgainPickupDate.value = tomorrowStr;
            }
            
            closeModal.forEach(btn => {
                btn.onclick = function() {
                    modal.style.display = "none";
                    document.getElementById("reserveAgainModal").style.display = "none";
                    document.body.style.overflow = '';
                };
            });
            
            window.onclick = function(e) { 
                if (e.target == modal) {
                    modal.style.display = "none";
                    document.body.style.overflow = '';
                }
                if (e.target == document.getElementById("reserveAgainModal")) {
                    closeReserveAgainModal();
                }
                if (e.target == document.getElementById("reserveAllModal")) {
                    closeReserveAllModal();
                }
            };
            
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    modal.style.display = "none";
                    document.getElementById("reserveAgainModal").style.display = "none";
                    document.getElementById("reserveAllModal").style.display = "none";
                    document.body.style.overflow = '';
                }
            });
            
            // Regular reservation form submit
            reservationForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const currentStock = parseInt(document.getElementById("modalQuantity").max);
                const requestedQty = parseInt(document.getElementById("modalQuantity").value);
                
                if (requestedQty > currentStock || currentStock === 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Insufficient Stock',
                        text: 'The requested quantity is not available.',
                        background: '#111', 
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                    return;
                }
                
                const formData = new FormData(this);
                
                fetch('cart_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        modal.style.display = "none";
                        document.body.style.overflow = '';
                        reservationForm.reset();
                        
                        const cartKey = document.getElementById('modalCartKey').value;
                        const cartItem = document.querySelector(`.cart-item[data-cart-key="${cartKey}"]`);
                        const itemQuantity = cartItem ? parseInt(cartItem.querySelector('.quantity-input').value) : 0;
                        
                        if(cartKey) {
                            fetch('cart_handler.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=remove_item&cart_key=' + encodeURIComponent(cartKey)
                            });
                            
                            if (cartItem) cartItem.remove();
                            updateCartCount(itemQuantity);
                        }
                        
                        Swal.fire({
                            title: 'RESERVATION SUCCESS!',
                            background: '#111',
                            color: '#fff',
                            icon: 'success',
                            iconColor: '#ff0000',
                            confirmButtonColor: '#ff0000',
                            html: `
                                <div style="font-family: 'Orbitron', sans-serif; text-align: center;">
                                    <p style="margin-bottom: 10px;">Show this ticket to the staff:</p>
                                    <h1 style="color: red; letter-spacing: 3px; font-size: 40px; border: 1px dashed red; padding: 10px;">${data.ticket}</h1>
                                    <p style="font-size: 0.8rem; color: #888; margin-top: 15px;">Please take a screenshot for your records.</p>
                                </div>
                            `
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Oops...', 
                            text: data.message || 'Something went wrong!', 
                            background: '#111', 
                            color: '#fff',
                            confirmButtonColor: '#ff0000'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Something went wrong. Please try again.',
                        background: '#111',
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                });
            });
            
            // Reserve again form submit
            reserveAgainForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const currentStock = parseInt(document.getElementById("reserveAgainQuantity").max);
                const requestedQty = parseInt(document.getElementById("reserveAgainQuantity").value);
                
                if (requestedQty > currentStock || currentStock === 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Insufficient Stock',
                        text: 'The requested quantity is not available.',
                        background: '#111', 
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                    return;
                }
                
                const formData = new FormData(this);
                
                fetch('cart_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById("reserveAgainModal").style.display = "none";
                        document.body.style.overflow = '';
                        reserveAgainForm.reset();
                        
                        Swal.fire({
                            title: 'RESERVATION SUCCESS!',
                            background: '#111',
                            color: '#fff',
                            icon: 'success',
                            iconColor: '#ff0000',
                            confirmButtonColor: '#ff0000',
                            html: `
                                <div style="font-family: 'Orbitron', sans-serif; text-align: center;">
                                    <p style="margin-bottom: 10px;">Show this ticket to the staff:</p>
                                    <h1 style="color: red; letter-spacing: 3px; font-size: 40px; border: 1px dashed red; padding: 10px;">${data.ticket}</h1>
                                    <p style="font-size: 0.8rem; color: #888; margin-top: 15px;">Please take a screenshot for your records.</p>
                                </div>
                            `
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Oops...', 
                            text: data.message || 'Something went wrong!', 
                            background: '#111', 
                            color: '#fff',
                            confirmButtonColor: '#ff0000'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Something went wrong. Please try again.',
                        background: '#111',
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                });
            });
        });
    </script>
    
    <!-- Footer Section -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-grid">
                <!-- Company Info -->
                <div class="footer-col">
                    <div class="footer-logo">
                        <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" alt="Ridershub Logo">
                        <span class="footer-brand">RIDERSHUB</span>
                    </div>
                    <p class="footer-description">
                        Your premier destination for premium motorcycle helmets and gears. Ride safe, ride stylish.
                    </p>
                   
                </div>

                <!-- Quick Links -->
                <div class="footer-col">
                    <h3 class="footer-title">QUICK LINKS</h3>
                    <ul class="footer-links">
                        <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                        <li><a href="index.php#products"><i class="fas fa-chevron-right"></i> Products</a></li>
                        <li><a href="cart.php"><i class="fas fa-chevron-right"></i> Cart</a></li>
                        <li><a href="#about"><i class="fas fa-chevron-right"></i> About Us</a></li>
                        <li><a href="#contact"><i class="fas fa-chevron-right"></i> Contact</a></li>
                    </ul>
                </div>

                <!-- Categories -->
                <div class="footer-col">
                    <h3 class="footer-title">HELMET TYPES</h3>
                    <ul class="footer-links">
                        <li><a href="index.php?type=full-face#products"><i class="fas fa-chevron-right"></i> Full Face</a></li>
                        <li><a href="index.php?type=modular#products"><i class="fas fa-chevron-right"></i> Modular</a></li>
                        <li><a href="index.php?type=half-face#products"><i class="fas fa-chevron-right"></i> Half Face</a></li>
                        <li><a href="index.php?type=open-face#products"><i class="fas fa-chevron-right"></i> Open Face</a></li>
                        <li><a href="index.php?type=off-road#products"><i class="fas fa-chevron-right"></i> Off Road</a></li>
                    </ul>
                </div>

                <!-- Contact Info -->
                <div class="footer-col">
                    <h3 class="footer-title">CONTACT US</h3>
                    <ul class="footer-contact">
                        <li>
                            <i class="fas fa-map-marker-alt"></i>
                            <span>43 paso de blas Valenzuela city</span>
                        </li>
                        <li>
                            <i class="fas fa-phone"></i>
                            <span>+63 956 966 3196</span>
                        </li>
                        <li>
                            <i class="fas fa-envelope"></i>
                            <span>kbridershub@gmail.com</span>
                        </li>
                        <li>
                            <i class="fas fa-clock"></i>
                            <span>Everyday: 9:00 AM - 8:00 PM</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Newsletter -->
            <div class="newsletter">
                <h3 class="newsletter-title">STAY UPDATED</h3>
                <p class="newsletter-text">Subscribe to get notified about new arrivals and exclusive offers</p>
                <form class="newsletter-form" onsubmit="event.preventDefault(); alert('Newsletter subscription feature coming soon!')">
                    <input type="email" placeholder="Your email address" required>
                    <button type="submit">SUBSCRIBE</button>
                </form>
            </div>

            <!-- Bottom Bar -->
            <div class="footer-bottom">
                <div class="copyright">
                    &copy; <?= date('Y') ?> RIDERSHUB. All rights reserved.
                </div>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                    <a href="#">Shipping Info</a>
                    <a href="#">Returns</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>