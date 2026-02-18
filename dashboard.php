<?php
session_start();
require_once 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Get comprehensive inventory stats with reservation data
$stats = [];
$query = "SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COUNT(DISTINCT b.id) as total_brands,
    SUM(CASE WHEN (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) <= 0 THEN 1 ELSE 0 END) as out_of_stock,
    SUM(CASE WHEN (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) > 0 
              AND (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) <= COALESCE(i.min_stock, 5) THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN (COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) > COALESCE(i.min_stock, 5) THEN 1 ELSE 0 END) as available_items,
    SUM(COALESCE(i.reserved_quantity, 0)) as total_reserved_units,
    SUM(COALESCE(i.quantity, p.quantity, p.stock, 0)) as total_inventory_units,
    SUM(GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0))) as total_available_units
FROM products p 
LEFT JOIN inventory i ON p.id = i.product_id 
LEFT JOIN brands b ON p.brand_id = b.id
WHERE p.status = 'active'";
$result = mysqli_query($conn, $query);
$stats = mysqli_fetch_assoc($result);

// Get reservation stats
$reservation_stats = [];
$res_query = "SELECT 
    COUNT(*) as total_reservations,
    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_reservations,
    SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed_reservations,
    SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled_reservations,
    SUM(CASE WHEN status = 'CONFIRMED' THEN quantity ELSE 0 END) as confirmed_units,
    COUNT(DISTINCT DATE(created_at)) as active_days
FROM reservations";
$res_result = mysqli_query($conn, $res_query);
$reservation_stats = mysqli_fetch_assoc($res_result);

// Get stock data for chart (top 10 products by available stock)
$stock_data = [];
$query = "SELECT p.name, 
                 COALESCE(i.quantity, p.quantity, p.stock, 0) as total_quantity,
                 COALESCE(i.reserved_quantity, 0) as reserved_quantity,
                 GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_quantity
          FROM products p 
          LEFT JOIN inventory i ON p.id = i.product_id 
          WHERE p.status = 'active' 
          ORDER BY available_quantity DESC 
          LIMIT 10";
$result = mysqli_query($conn, $query);
while($row = mysqli_fetch_assoc($result)) {
    $stock_data[] = $row;
}

// Get recent reservations for activity feed
$recent_reservations = [];
$recent_query = "SELECT r.*, p.name as product_name, p.image 
                 FROM reservations r 
                 JOIN products p ON r.product_id = p.id 
                 ORDER BY r.created_at DESC 
                 LIMIT 5";
$recent_result = mysqli_query($conn, $recent_query);
while($row = mysqli_fetch_assoc($recent_result)) {
    $recent_reservations[] = $row;
}

// Get low stock alerts
$low_stock_alerts = [];
$alert_query = "SELECT p.id, p.name, p.image, 
                       COALESCE(i.quantity, p.quantity, p.stock, 0) as total_stock,
                       COALESCE(i.reserved_quantity, 0) as reserved_stock,
                       GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) as available_stock,
                       COALESCE(i.min_stock, 5) as min_stock
                FROM products p 
                LEFT JOIN inventory i ON p.id = i.product_id 
                WHERE p.status = 'active' 
                  AND GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) <= COALESCE(i.min_stock, 5)
                  AND GREATEST(0, COALESCE(i.quantity, p.quantity, p.stock, 0) - COALESCE(i.reserved_quantity, 0)) > 0
                ORDER BY available_stock ASC 
                LIMIT 5";
$alert_result = mysqli_query($conn, $alert_query);
while($row = mysqli_fetch_assoc($alert_result)) {
    $low_stock_alerts[] = $row;
}

// Calculate percentages for pie chart
$total_products = $stats['total_products'] ?? 0;
$available_count = $stats['available_items'] ?? 0;
$low_stock_count = $stats['low_stock'] ?? 0;
$out_of_stock_count = $stats['out_of_stock'] ?? 0;

// Get daily reservation trend (last 7 days)
$trend_data = [];
$trend_query = "SELECT 
    DATE(created_at) as date,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed
FROM reservations 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY date ASC";
$trend_result = mysqli_query($conn, $trend_query);
while($row = mysqli_fetch_assoc($trend_result)) {
    $trend_data[] = $row;
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
    <style>
        .stat-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
            border-radius: 15px;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .stat-card i {
            opacity: 0.8;
            transition: transform 0.3s ease;
        }
        .stat-card:hover i {
            transform: scale(1.1);
        }
        .alert-item {
            padding: 10px;
            border-left: 4px solid #ffc107;
            background: rgba(255, 193, 7, 0.1);
            margin-bottom: 10px;
            border-radius: 5px;
            transition: transform 0.2s ease;
        }
        .alert-item:hover {
            transform: translateX(5px);
        }
        .alert-item.critical {
            border-left-color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }
        .activity-feed {
            max-height: 300px;
            overflow-y: auto;
            padding-right: 10px;
        }
        .activity-feed::-webkit-scrollbar {
            width: 5px;
        }
        .activity-feed::-webkit-scrollbar-thumb {
            background: red;
            border-radius: 10px;
        }
        .activity-item {
            padding: 12px;
            border-bottom: 1px solid #333;
            transition: background 0.3s ease;
        }
        .activity-item:hover {
            background: #1a1a1a;
        }
        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-pending {
            background: #ffc107;
            color: #000;
        }
        .status-confirmed {
            background: #28a745;
            color: #fff;
        }
        .status-cancelled {
            background: #dc3545;
            color: #fff;
        }
        .quick-action-btn {
            background: rgba(255,0,0,0.1);
            border: 1px solid red;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .quick-action-btn:hover {
            background: red;
            color: white;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="bi bi-speedometer2"></i> Dashboard Overview
                </h1>
            </div>
        </div>

        <!-- Quick Action Buttons -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex gap-2 flex-wrap">
                    <a href="products.php" class="quick-action-btn">
                        <i class="bi bi-box"></i> Manage Products
                    </a>
                    <a href="inventory.php" class="quick-action-btn">
                        <i class="bi bi-clipboard-data"></i> Manage Inventory
                    </a>
                    <a href="reservations.php" class="quick-action-btn">
                        <i class="bi bi-calendar-check"></i> View Reservations
                    </a>
                    <a href="reservations.php?status=PENDING" class="quick-action-btn">
                        <i class="bi bi-clock-history"></i> Pending (<?php echo $reservation_stats['pending_reservations'] ?? 0; ?>)
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats Cards Row 1 - Inventory Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Total Products</h5>
                                <h2><?php echo number_format($stats['total_products'] ?? 0); ?></h2>
                                <small><?php echo number_format($stats['total_brands'] ?? 0); ?> brands</small>
                            </div>
                            <i class="bi bi-box-seam" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Total Units</h5>
                                <h2><?php echo number_format($stats['total_inventory_units'] ?? 0); ?></h2>
                                <small>In inventory</small>
                            </div>
                            <i class="bi bi-boxes" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Available Units</h5>
                                <h2><?php echo number_format($stats['total_available_units'] ?? 0); ?></h2>
                                <small>Ready for sale</small>
                            </div>
                            <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Reserved Units</h5>
                                <h2><?php echo number_format($stats['total_reserved_units'] ?? 0); ?></h2>
                                <small>From reservations</small>
                            </div>
                            <i class="bi bi-lock" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards Row 2 - Reservation Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Total Reservations</h5>
                                <h2><?php echo number_format($reservation_stats['total_reservations'] ?? 0); ?></h2>
                            </div>
                            <i class="bi bi-calendar-check" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Pending</h5>
                                <h2><?php echo number_format($reservation_stats['pending_reservations'] ?? 0); ?></h2>
                            </div>
                            <i class="bi bi-clock-history" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Confirmed</h5>
                                <h2><?php echo number_format($reservation_stats['confirmed_reservations'] ?? 0); ?></h2>
                                <small><?php echo number_format($reservation_stats['confirmed_units'] ?? 0); ?> units</small>
                            </div>
                            <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-secondary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">Cancelled</h5>
                                <h2><?php echo number_format($reservation_stats['cancelled_reservations'] ?? 0); ?></h2>
                            </div>
                            <i class="bi bi-x-circle" style="font-size: 3rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Alerts Row -->
        <div class="row">
            <!-- Stock Levels Chart -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-bar-chart"></i> Top Products by Available Stock
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="stockChart" height="250"></canvas>
                    </div>
                </div>
            </div>

            <!-- Inventory Distribution Chart -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-pie-chart"></i> Inventory Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="inventoryPieChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Alerts and Recent Activity Row -->
        <div class="row">
            <!-- Low Stock Alerts -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="bi bi-exclamation-triangle"></i> Low Stock Alerts
                            <?php if(count($low_stock_alerts) > 0): ?>
                            <span class="badge bg-dark text-white ms-2"><?php echo count($low_stock_alerts); ?></span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($low_stock_alerts)): ?>
                            <div class="text-center text-success py-4">
                                <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                                <p class="mt-2">All products have sufficient stock!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach($low_stock_alerts as $alert): ?>
                            <div class="alert-item <?php echo $alert['available_stock'] <= 2 ? 'critical' : ''; ?>">
                                <div class="d-flex align-items-center gap-3">
                                    <?php if($alert['image']): ?>
                                    <img src="uploads/<?php echo $alert['image']; ?>" 
                                         alt="" style="width: 40px; height: 40px; object-fit: cover; border-radius: 5px;">
                                    <?php else: ?>
                                    <div style="width: 40px; height: 40px; background: #333; border-radius: 5px; display: flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-image text-muted"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold"><?php echo htmlspecialchars($alert['name']); ?></div>
                                        <small class="text-muted">
                                            Available: <span class="text-<?php echo $alert['available_stock'] <= 2 ? 'danger' : 'warning'; ?> fw-bold"><?php echo $alert['available_stock']; ?></span>
                                            | Total: <?php echo $alert['total_stock']; ?>
                                            | Reserved: <?php echo $alert['reserved_stock']; ?>
                                        </small>
                                    </div>
                                    <a href="inventory.php?product_id=<?php echo $alert['id']; ?>" class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="text-center mt-3">
                                <a href="inventory.php?stock=low" class="btn btn-sm btn-outline-warning">
                                    View All Low Stock Items
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Feed -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history"></i> Recent Reservations
                        </h5>
                    </div>
                    <div class="card-body activity-feed">
                        <?php if(empty($recent_reservations)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-calendar-x" style="font-size: 3rem;"></i>
                                <p class="mt-2">No recent reservations</p>
                            </div>
                        <?php else: ?>
                            <?php foreach($recent_reservations as $res): ?>
                            <div class="activity-item">
                                <div class="d-flex align-items-center gap-3">
                                    <?php if($res['image']): ?>
                                    <img src="uploads/<?php echo $res['image']; ?>" 
                                         alt="" style="width: 40px; height: 40px; object-fit: cover; border-radius: 5px;">
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="fw-bold"><?php echo htmlspecialchars($res['product_name']); ?></span>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($res['customer_name']); ?> • 
                                                    Qty: <?php echo $res['quantity']; ?>
                                                </small>
                                            </div>
                                            <span class="status-badge status-<?php echo strtolower($res['status']); ?>">
                                                <?php echo $res['status']; ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <small class="text-muted">
                                                <i class="bi bi-ticket"></i> <?php echo $res['ticket_number']; ?>
                                            </small>
                                            <small class="text-muted">
                                                <?php echo date('M d, h:i A', strtotime($res['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="text-center mt-3">
                                <a href="reservations.php" class="btn btn-sm btn-outline-primary">
                                    View All Reservations
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reservation Trend Chart -->
        <?php if(!empty($trend_data)): ?>
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-graph-up"></i> Reservation Trend (Last 7 Days)
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="trendChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Stock Levels Bar Chart
        const stockCtx = document.getElementById('stockChart').getContext('2d');
        new Chart(stockCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($item) {
                    return strlen($item['name']) > 20 ? substr($item['name'], 0, 20) . '...' : $item['name'];
                }, $stock_data)); ?>,
                datasets: [{
                    label: 'Available Stock',
                    data: <?php echo json_encode(array_column($stock_data, 'available_quantity')); ?>,
                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 1
                }, {
                    label: 'Reserved Stock',
                    data: <?php echo json_encode(array_column($stock_data, 'reserved_quantity')); ?>,
                    backgroundColor: 'rgba(255, 193, 7, 0.7)',
                    borderColor: 'rgba(255, 193, 7, 1)',
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
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            afterBody: function(context) {
                                return 'Total: ' + <?php echo json_encode(array_column($stock_data, 'total_quantity')); ?>[context[0].dataIndex];
                            }
                        }
                    }
                }
            }
        });

        // Inventory Distribution Pie Chart
        const pieCtx = document.getElementById('inventoryPieChart').getContext('2d');
        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['In Stock', 'Low Stock', 'Out of Stock'],
                datasets: [{
                    data: [<?php echo $available_count; ?>, <?php echo $low_stock_count; ?>, <?php echo $out_of_stock_count; ?>],
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.7)',
                        'rgba(255, 193, 7, 0.7)',
                        'rgba(220, 53, 69, 0.7)'
                    ],
                    borderColor: [
                        'rgba(40, 167, 69, 1)',
                        'rgba(255, 193, 7, 1)',
                        'rgba(220, 53, 69, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = <?php echo $total_products; ?>;
                                const value = context.raw;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${context.label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        <?php if(!empty($trend_data)): ?>
        // Reservation Trend Line Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($trend_data, 'date')); ?>,
                datasets: [{
                    label: 'Pending',
                    data: <?php echo json_encode(array_column($trend_data, 'pending')); ?>,
                    borderColor: 'rgba(255, 193, 7, 1)',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.4,
                    fill: false
                }, {
                    label: 'Confirmed',
                    data: <?php echo json_encode(array_column($trend_data, 'confirmed')); ?>,
                    borderColor: 'rgba(40, 167, 69, 1)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>