<?php
require_once 'config.php';

echo "<h1>🛠️ Fixing Product Images</h1>";

// Kunin lahat ng products
$result = $conn->query("SELECT id, name, image FROM products");

while($row = $result->fetch_assoc()) {
    $id = $row['id'];
    $old_image = $row['image'];
    
    // Remove numbers and underscore sa unahan (pattern: 1234567890_)
    $new_image = preg_replace('/^\d+_/', '', $old_image);
    
    echo "<div style='margin-bottom: 15px; padding: 10px; border: 1px solid #333;'>";
    echo "<strong>Product:</strong> " . $row['name'] . "<br>";
    echo "<strong>Old Image:</strong> " . $old_image . "<br>";
    echo "<strong>New Image:</strong> " . $new_image . "<br>";
    
    // I-update ang database
    $update = $conn->query("UPDATE products SET image = '$new_image' WHERE id = $id");
    
    if($update) {
        echo "<span style='color: green;'>✅ Updated successfully!</span><br>";
    } else {
        echo "<span style='color: red;'>❌ Update failed: " . $conn->error . "</span><br>";
    }
    echo "</div>";
}

echo "<br>🎉 Done! <a href='index.php' style='color: red;'>Go to Homepage</a>";
?>