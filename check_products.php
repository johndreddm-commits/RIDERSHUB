<?php
require_once 'config.php';

$result = $conn->query("SELECT id, name, image FROM products");

echo "<h1>📋 Current Products</h1>";
while($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id'] . "<br>";
    echo "Name: " . $row['name'] . "<br>";
    echo "Image: " . $row['image'] . "<br>";
    echo "------------------------<br>";
}
?>