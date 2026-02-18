<?php
session_start();
require_once "config.php";

// Turn on error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_GET['id']) && isset($_GET['status'])) {
    $res_id = (int)$_GET['id'];
    $status = $_GET['status'];

    $conn->begin_transaction();

    try {
        // 1. Get reservation data
        $res_query = $conn->prepare("SELECT product_id FROM reservations WHERE id = ?");
        $res_query->bind_param("i", $res_id);
        $res_query->execute();
        $res_data = $res_query->get_result()->fetch_assoc();

        if (!$res_data) throw new Exception("Reservation ID $res_id not found in database.");

        $p_id = $res_data['product_id'];
        $qty_needed = 1; // Since reservations don't have quantity column, assume 1

        // 2. Logic for CONFIRMATION
        if ($status === 'CONFIRMED') {
            // Check stock from both products and inventory tables
            $stock_query = $conn->prepare("
                SELECT 
                    p.stock as product_stock,
                    p.quantity as product_quantity,
                    COALESCE(i.quantity, p.stock) as inventory_stock
                FROM products p 
                LEFT JOIN inventory i ON p.id = i.product_id 
                WHERE p.id = ?
            ");
            $stock_query->bind_param("i", $p_id);
            $stock_query->execute();
            $p_data = $stock_query->get_result()->fetch_assoc();

            if (!$p_data) throw new Exception("Product ID $p_id not found in inventory.");
            
            // Use the most appropriate stock value
            $available_stock = $p_data['inventory_stock'] ?: $p_data['product_stock'] ?: $p_data['product_quantity'];
            
            if ($available_stock < $qty_needed) {
                throw new Exception("Low Stock! Available: " . $available_stock . ", Needed: " . $qty_needed);
            }

            // Update products table - BOTH stock and quantity columns
            $deduct_product = $conn->prepare("
                UPDATE products 
                SET stock = stock - ?, 
                    quantity = quantity - ? 
                WHERE id = ?
            ");
            $deduct_product->bind_param("iii", $qty_needed, $qty_needed, $p_id);
            if (!$deduct_product->execute()) {
                throw new Exception("Failed to deduct product stock: " . $conn->error);
            }

            // Update inventory table if it exists
            $check_inventory = $conn->prepare("SELECT id FROM inventory WHERE product_id = ?");
            $check_inventory->bind_param("i", $p_id);
            $check_inventory->execute();
            $inventory_exists = $check_inventory->get_result()->num_rows > 0;

            if ($inventory_exists) {
                // Update existing inventory
                $deduct_inventory = $conn->prepare("
                    UPDATE inventory 
                    SET quantity = quantity - ? 
                    WHERE product_id = ?
                ");
                $deduct_inventory->bind_param("ii", $qty_needed, $p_id);
                if (!$deduct_inventory->execute()) {
                    throw new Exception("Failed to deduct inventory: " . $conn->error);
                }
            } else {
                // Insert new inventory record with remaining stock
                $new_stock = $available_stock - $qty_needed;
                $insert_inventory = $conn->prepare("
                    INSERT INTO inventory (product_id, quantity, min_stock) 
                    VALUES (?, ?, ?)
                ");
                $default_min_stock = 5;
                $insert_inventory->bind_param("iii", $p_id, $new_stock, $default_min_stock);
                if (!$insert_inventory->execute()) {
                    throw new Exception("Failed to create inventory record: " . $conn->error);
                }
            }

            // Log the inventory change
            $log_query = $conn->prepare("
                INSERT INTO inventory_logs (product_id, action, quantity) 
                VALUES (?, 'reservation_confirmed', ?)
            ");
            $log_query->bind_param("ii", $p_id, $qty_needed);
            $log_query->execute();
        }

        // 3. UPDATE RESERVATION STATUS
        $update_status = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
        $update_status->bind_param("si", $status, $res_id);
        if (!$update_status->execute()) {
            throw new Exception("Failed to update status: " . $conn->error);
        }

        $conn->commit();
        
        // Set success message based on status
        if ($status === 'CONFIRMED') {
            $_SESSION['msg_success'] = "Reservation #$res_id confirmed and stock updated!";
        } elseif ($status === 'CANCELLED') {
            // If cancelling a confirmed reservation, restore stock
            $restore_query = $conn->prepare("
                UPDATE products 
                SET stock = stock + 1, 
                    quantity = quantity + 1 
                WHERE id = ?
            ");
            $restore_query->bind_param("i", $p_id);
            $restore_query->execute();
            
            // Also update inventory if exists
            $restore_inventory = $conn->prepare("
                UPDATE inventory 
                SET quantity = quantity + 1 
                WHERE product_id = ?
            ");
            $restore_inventory->bind_param("i", $p_id);
            $restore_inventory->execute();
            
            $_SESSION['msg_success'] = "Reservation #$res_id cancelled and stock restored!";
        } else {
            $_SESSION['msg_success'] = "Reservation #$res_id status updated to $status!";
        }

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['msg_error'] = "Error: " . $e->getMessage();
    }
} else {
    $_SESSION['msg_error'] = "Invalid request parameters!";
}

header("Location: dashboard.php?view=reservations");
exit();
?>