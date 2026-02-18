<?php
session_start();
require_once "config.php";



if(isset($_POST['add_brand'])){
    // Clean the input: convert to uppercase and remove extra spaces
    $brand = strtoupper(trim($_POST['new_brand']));
    
    if(!empty($brand)){
        // Prepare the SQL to prevent duplicates
        $stmt = $conn->prepare("INSERT INTO brands (brand_name) VALUES (?)");
        $stmt->bind_param("s", $brand);
        
        if($stmt->execute()){
            // Redirect back to inventory with a success message
            header("Location: dashboard.php?view=inventory&status=brand_added");
            exit;
        } else {
            // If the brand already exists, handle the error gracefully
            header("Location: dashboard.php?view=inventory&status=duplicate");
            exit;
        }
    }
}
?>