<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Image Debugger</h1>";

// Get all files in images folder
$image_files = scandir('images');
$actual_images = array_diff($image_files, array('.', '..'));

echo "<h2>📁 Files in 'images/' folder (" . count($actual_images) . " files):</h2>";
echo "<ul style='columns: 3;'>";
foreach($actual_images as $file) {
    echo "<li>" . $file . "</li>";
}
echo "</ul>";

// Connect to database
require_once 'config.php';

echo "<h2>📊 Products from Database:</h2>";
$result = $conn->query("SELECT id, name, image FROM products");

echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #333; color: white;'><th>Product ID</th><th>Product Name</th><th>Image in DB</th><th>Status</th><th>Preview</th></tr>";

while($row = $result->fetch_assoc()) {
    $image_name = $row['image'];
    $image_path = "images/" . $image_name;
    
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['name'] . "</td>";
    echo "<td><code>" . $image_name . "</code></td>";
    
    if (file_exists($image_path)) {
        echo "<td style='color: green;'>✅ FOUND</td>";
        echo "<td><img src='$image_path' width='100' style='border: 2px solid green;'></td>";
    } else {
        echo "<td style='color: red;'>❌ NOT FOUND</td>";
        echo "<td>No image</td>";
    }
    echo "</tr>";
}
echo "</table>";

// Check if images folder is readable
echo "<h2>📁 Folder Permissions:</h2>";
echo "Images folder path: " . realpath('images') . "<br>";
echo "Is readable? " . (is_readable('images') ? '✅ Yes' : '❌ No') . "<br>";
echo "Is writable? " . (is_writable('images') ? '✅ Yes' : '❌ No') . "<br>";
?>