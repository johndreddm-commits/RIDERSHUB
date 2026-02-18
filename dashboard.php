<?php
session_start();
require_once 'config.php';

// Get inventory stats
$stats = [];
$query = "SELECT 
    COUNT(*) as total_products,
    SUM(CASE WHEN p.stock <= i.min_stock THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN p.stock > i.min_stock THEN 1 ELSE 0 END) as available_items
FROM products p 
LEFT JOIN inventory i ON p.id = i.product_id 
WHERE p.status = 'active'";
$result = mysqli_query($conn, $query);
$stats = mysqli_fetch_assoc($result);

// Get stock data for chart
$stock_data = [];
$query = "SELECT p.name, i.quantity 
          FROM products p 
          LEFT JOIN inventory i ON p.id = i.product_id 
          WHERE p.status = 'active' 
          ORDER BY i.quantity DESC 
          LIMIT 10";
$result = mysqli_query($conn, $query);
while($row = mysqli_fetch_assoc($result)) {
    $stock_data[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - KB Riders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="dashboard.css">
 
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">Dashboard Overview</h1>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card stat-card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Total Products</h5>
                                <h2><?php echo $stats['total_products'] ?? 0; ?></h2>
                            </div>
                            <i class="bi bi-box-seam" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Low stock</h5>
                                <h2><?php echo $stats['low_stock'] ?? 0; ?></h2>
                            </div>
                            <i class="bi bi-exclamation-triangle" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Available Items</h5>
                                <h2><?php echo $stats['available_items'] ?? 0; ?></h2>
                            </div>
                            <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Stock Levels</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="stockChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Inventory Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="inventoryPieChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Bar Chart for Stock Levels
        const stockCtx = document.getElementById('stockChart').getContext('2d');
        const stockChart = new Chart(stockCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($stock_data, 'name')); ?>,
                datasets: [{
                    label: 'Stock Quantity',
                    data: <?php echo json_encode(array_column($stock_data, 'quantity')); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Quantity'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Products'
                        }
                    }
                }
            }
        });

        // Pie Chart for Inventory Distribution
        const pieCtx = document.getElementById('inventoryPieChart').getContext('2d');
        const pieChart = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['Available', 'Low Stock', 'Out of Stock'],
                datasets: [{
                    data: [
                        <?php echo $stats['available_items'] ?? 0; ?>,
                        <?php echo $stats['low_stock'] ?? 0; ?>,
                        <?php echo max(0, ($stats['total_products'] ?? 0) - ($stats['available_items'] ?? 0) - ($stats['low_stock'] ?? 0)); ?>
                    ],
                    backgroundColor: [
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(255, 205, 86, 0.7)',
                        'rgba(255, 99, 132, 0.7)'
                    ],
                    borderColor: [
                        'rgba(75, 192, 192, 1)',
                        'rgba(255, 205, 86, 1)',
                        'rgba(255, 99, 132, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>