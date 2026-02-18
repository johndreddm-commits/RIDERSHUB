<?php
require_once "../config.php";
session_start();

// Check if admin is logged in
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Confirm reservation
if(isset($_POST['confirm_reservation'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    
    // Get reservation details
    $get_res = "SELECT r.user_id, r.reservation_code, p.name as product_name 
                FROM reservations r 
                LEFT JOIN products p ON r.product_id = p.id 
                WHERE r.id = $reservation_id";
    $res_result = mysqli_query($conn, $get_res);
    $res_data = mysqli_fetch_assoc($res_result);
    
    if($res_data) {
        // Update status
        $update = "UPDATE reservations SET status = 'confirmed' WHERE id = $reservation_id";
        if(mysqli_query($conn, $update)) {
            // Create notification
            $message = "Your reservation for {$res_data['product_name']} has been confirmed and is ready for pickup.";
            $notif_query = "INSERT INTO notifications (user_id, title, message, type, reservation_code) 
                            VALUES ({$res_data['user_id']}, 'Reservation Confirmed', '$message', 'confirmed', '{$res_data['reservation_code']}')";
            mysqli_query($conn, $notif_query);
            
            $_SESSION['success'] = "Reservation confirmed successfully!";
        }
    }
    header("Location: reservations.php");
    exit;
}

// Cancel reservation
if(isset($_POST['cancel_reservation'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    
    // Get reservation details
    $get_res = "SELECT r.user_id, r.reservation_code, p.name as product_name 
                FROM reservations r 
                LEFT JOIN products p ON r.product_id = p.id 
                WHERE r.id = $reservation_id";
    $res_result = mysqli_query($conn, $get_res);
    $res_data = mysqli_fetch_assoc($res_result);
    
    if($res_data) {
        // Update status
        $update = "UPDATE reservations SET status = 'cancelled' WHERE id = $reservation_id";
        if(mysqli_query($conn, $update)) {
            // Create notification
            $message = "Your reservation for {$res_data['product_name']} has been cancelled. Please contact support.";
            $notif_query = "INSERT INTO notifications (user_id, title, message, type, reservation_code) 
                            VALUES ({$res_data['user_id']}, 'Reservation Cancelled', '$message', 'cancel', '{$res_data['reservation_code']}')";
            mysqli_query($conn, $notif_query);
            
            $_SESSION['success'] = "Reservation cancelled successfully!";
        }
    }
    header("Location: reservations.php");
    exit;
}
?>