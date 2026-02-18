<?php
session_start();
require_once "config.php";

$response = ["success" => false, "ticket" => ""];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $product_id = $_POST['product_id'];
    $name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $date = $_POST['pickup_date'];
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    // Get selected color and size
    $selected_color = isset($_POST['selected_color']) ? mysqli_real_escape_string($conn, $_POST['selected_color']) : '';
    $selected_size = isset($_POST['selected_size']) ? mysqli_real_escape_string($conn, $_POST['selected_size']) : '';
    
    // Validate quantity (must be positive integer)
    if($quantity <= 0) {
        $quantity = 1;
    }
    
    // First, check if product has enough stock
    $stock_check = "SELECT stock, quantity FROM products WHERE id = ?";
    if($stmt_check = $conn->prepare($stock_check)){
        $stmt_check->bind_param("i", $product_id);
        $stmt_check->execute();
        $stmt_check->bind_result($current_stock, $current_quantity);
        $stmt_check->fetch();
        $stmt_check->close();
        
        // Use stock if available, otherwise use quantity
        $available_stock = ($current_stock > 0) ? $current_stock : $current_quantity;
        
        if($quantity > $available_stock) {
            $response["message"] = "Not enough stock available. Available: $available_stock, Requested: $quantity";
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
    }
    
    // Generate a unique ticket like KB-A1B2
    $ticket = "KB-" . strtoupper(bin2hex(random_bytes(2)));

    // Check which columns exist in the reservations table
    $check_columns = "SHOW COLUMNS FROM reservations";
    $result = $conn->query($check_columns);
    $columns = [];
    while($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Determine which columns to include in the insert
    $has_quantity = in_array('quantity', $columns);
    $has_selected_color = in_array('selected_color', $columns);
    $has_selected_size = in_array('selected_size', $columns);
    
    // Build dynamic SQL query based on available columns
    $sql_fields = "product_id, customer_name, phone, pickup_date, ticket_number";
    $sql_placeholders = "?, ?, ?, ?, ?";
    $param_types = "issss";
    $param_values = [$product_id, $name, $phone, $date, $ticket];
    
    // Add quantity if column exists
    if($has_quantity) {
        $sql_fields .= ", quantity";
        $sql_placeholders .= ", ?";
        $param_types .= "i";
        $param_values[] = $quantity;
    }
    
    // Add selected_color if column exists
    if($has_selected_color) {
        $sql_fields .= ", selected_color";
        $sql_placeholders .= ", ?";
        $param_types .= "s";
        $param_values[] = $selected_color;
    }
    
    // Add selected_size if column exists
    if($has_selected_size) {
        $sql_fields .= ", selected_size";
        $sql_placeholders .= ", ?";
        $param_types .= "s";
        $param_values[] = $selected_size;
    }
    
    $sql = "INSERT INTO reservations ($sql_fields) VALUES ($sql_placeholders)";
    
    if($stmt = $conn->prepare($sql)){
        // Dynamically bind parameters
        $stmt->bind_param($param_types, ...$param_values);
        
        if($stmt->execute()){
            $response["success"] = true;
            $response["ticket"] = $ticket;
            
            // Optional: Immediately deduct from stock if you want reservations to hold stock
            // Uncomment if you want reservations to immediately reduce available stock
            /*
            $deduct_query = "UPDATE products 
                            SET stock = stock - ?, 
                                quantity = quantity - ? 
                            WHERE id = ?";
            if($stmt_deduct = $conn->prepare($deduct_query)){
                $stmt_deduct->bind_param("iii", $quantity, $quantity, $product_id);
                $stmt_deduct->execute();
                $stmt_deduct->close();
            }
            */
        } else {
            $response["message"] = "Database error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $response["message"] = "Error preparing statement: " . $conn->error;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>