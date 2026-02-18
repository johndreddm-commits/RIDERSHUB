<?php
session_start();
require_once 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Handle actions
if(isset($_GET['action'])) {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    
    // Get reservation details before any action
    $reservation_query = "SELECT r.*, p.name as product_name, p.image, p.stock, p.current_stock,
                                 p.reserved_stock, i.quantity as inventory_qty, i.reserved_quantity,
                                 r.user_id
                          FROM reservations r 
                          JOIN products p ON r.product_id = p.id 
                          LEFT JOIN inventory i ON r.product_id = i.product_id 
                          WHERE r.id = ?";
    $stmt = mysqli_prepare($conn, $reservation_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $reservation_result = mysqli_stmt_get_result($stmt);
    $reservation = mysqli_fetch_assoc($reservation_result);
    
    if($_GET['action'] == 'confirm') {
        if($reservation['status'] != 'CONFIRMED') {
            $product_id = $reservation['product_id'];
            $user_id = $reservation['user_id'];
            
            // Get current stock
            $product_query = "SELECT p.*, i.quantity as inventory_qty, i.reserved_quantity as inv_reserved
                             FROM products p 
                             LEFT JOIN inventory i ON p.id = i.product_id 
                             WHERE p.id = ?";
            $stmt = mysqli_prepare($conn, $product_query);
            mysqli_stmt_bind_param($stmt, "i", $product_id);
            mysqli_stmt_execute($stmt);
            $product_result = mysqli_stmt_get_result($stmt);
            $product = mysqli_fetch_assoc($product_result);
            
            $reservation_quantity = isset($reservation['quantity']) && $reservation['quantity'] > 0 ? $reservation['quantity'] : 1;
            
            // Check available stock (total - reserved)
            $available_stock = $product['inventory_qty'] ?? $product['current_stock'] ?? $product['stock'] ?? 0;
            
            if($available_stock >= $reservation_quantity) {
                mysqli_begin_transaction($conn);
                
                try {
                    // Update reservation status
                    $update_res = "UPDATE reservations SET status = 'CONFIRMED' WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_res);
                    mysqli_stmt_bind_param($stmt, "i", $id);
                    mysqli_stmt_execute($stmt);
                    
                    // Update inventory - DECREASE quantity
                    $update_inv = "UPDATE inventory 
                                   SET quantity = quantity - ?,
                                       last_updated = NOW()
                                   WHERE product_id = ?";
                    $stmt = mysqli_prepare($conn, $update_inv);
                    mysqli_stmt_bind_param($stmt, "ii", $reservation_quantity, $product_id);
                    mysqli_stmt_execute($stmt);
                    
                    // Update products table - DECREASE current_stock
                    $update_prod = "UPDATE products 
                                    SET current_stock = current_stock - ?,
                                        available_stock = (current_stock - reserved_stock)
                                    WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_prod);
                    mysqli_stmt_bind_param($stmt, "ii", $reservation_quantity, $product_id);
                    mysqli_stmt_execute($stmt);
                    
                    // Log the action
                    $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, reference_id, reference_type, created_at) 
                                  VALUES (?, 'reservation_confirm', ?, ?, 'reservation', NOW())";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, "iii", $product_id, $reservation_quantity, $id);
                    mysqli_stmt_execute($log_stmt);
                    
                    // ✅ FIXED: Add notification for user - correct parameter binding
                    $notif_query = "INSERT INTO notifications (user_id, title, message, type, reservation_code, created_at) 
                                    VALUES (?, ?, ?, ?, ?, NOW())";
                    $notif_stmt = mysqli_prepare($conn, $notif_query);
                    $notif_title = 'Reservation Confirmed';
                    $notif_type = 'confirmed';
                    $notif_msg = "Your reservation for " . $reservation['product_name'] . " (x" . $reservation_quantity . ") has been confirmed. Please proceed to the store for pickup.";
                    mysqli_stmt_bind_param($notif_stmt, "issss", $user_id, $notif_title, $notif_msg, $notif_type, $reservation['ticket_number']);
                    mysqli_stmt_execute($notif_stmt);
                    
                    mysqli_commit($conn);
                    
                    $_SESSION['message'] = "Reservation confirmed successfully! $reservation_quantity unit(s) deducted from available stock.";
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $_SESSION['error'] = "Error confirming reservation: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "Cannot confirm reservation: Not enough available stock! Available: $available_stock, Requested: $reservation_quantity";
            }
        } else {
            $_SESSION['message'] = "Reservation is already confirmed!";
        }
        
    } elseif($_GET['action'] == 'cancel') {
        $product_id = $reservation['product_id'];
        $user_id = $reservation['user_id'];
        $reservation_quantity = isset($reservation['quantity']) && $reservation['quantity'] > 0 ? $reservation['quantity'] : 1;
        
        mysqli_begin_transaction($conn);
        
        try {
            if($reservation['status'] == 'CONFIRMED') {
                // If confirmed, restore stock and remove from reserved
                $update_inv = "UPDATE inventory 
                               SET quantity = quantity + ?,
                                   reserved_quantity = reserved_quantity - ?,
                                   last_updated = NOW()
                               WHERE product_id = ?";
                $stmt = mysqli_prepare($conn, $update_inv);
                mysqli_stmt_bind_param($stmt, "iii", $reservation_quantity, $reservation_quantity, $product_id);
                mysqli_stmt_execute($stmt);
                
                $update_prod = "UPDATE products 
                                SET current_stock = current_stock + ?,
                                    reserved_stock = reserved_stock - ?,
                                    available_stock = (current_stock - reserved_stock)
                                WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_prod);
                mysqli_stmt_bind_param($stmt, "iii", $reservation_quantity, $reservation_quantity, $product_id);
                mysqli_stmt_execute($stmt);
                
                $action_log = 'reservation_cancel_confirmed';
                $notif_type = 'cancelled';
                $notif_title = 'Reservation Cancelled';
                $notif_msg = "Your confirmed reservation for " . $reservation['product_name'] . " (x" . $reservation_quantity . ") has been cancelled.";
            } else {
                // If pending, just cancel - decrease reserved_quantity only
                $update_inv = "UPDATE inventory 
                               SET reserved_quantity = reserved_quantity - ?,
                                   last_updated = NOW()
                               WHERE product_id = ?";
                $stmt = mysqli_prepare($conn, $update_inv);
                mysqli_stmt_bind_param($stmt, "ii", $reservation_quantity, $product_id);
                mysqli_stmt_execute($stmt);
                
                $update_prod = "UPDATE products 
                                SET reserved_stock = reserved_stock - ?,
                                    available_stock = (current_stock - reserved_stock)
                                WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_prod);
                mysqli_stmt_bind_param($stmt, "ii", $reservation_quantity, $product_id);
                mysqli_stmt_execute($stmt);
                
                $action_log = 'reservation_cancel_pending';
                $notif_type = 'cancelled';
                $notif_title = 'Reservation Cancelled';
                $notif_msg = "Your pending reservation for " . $reservation['product_name'] . " (x" . $reservation_quantity . ") has been cancelled.";
            }
            
            // Update reservation status
            $update_res = "UPDATE reservations SET status = 'CANCELLED' WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_res);
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            
            // Log the action
            $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, reference_id, reference_type, created_at) 
                          VALUES (?, ?, ?, ?, 'reservation', NOW())";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "isii", $product_id, $action_log, $reservation_quantity, $id);
            mysqli_stmt_execute($log_stmt);
            
            // ✅ FIXED: Add notification for user - correct parameter binding
            $notif_query = "INSERT INTO notifications (user_id, title, message, type, reservation_code, created_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())";
            $notif_stmt = mysqli_prepare($conn, $notif_query);
            mysqli_stmt_bind_param($notif_stmt, "issss", $user_id, $notif_title, $notif_msg, $notif_type, $reservation['ticket_number']);
            mysqli_stmt_execute($notif_stmt);
            
            mysqli_commit($conn);
            
            $_SESSION['message'] = "Reservation cancelled successfully!";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error'] = "Error cancelling reservation: " . $e->getMessage();
        }
    }
    
    header("Location: reservations.php");
    exit();
}

// Handle filters
$where_clause = "1=1";
$params = [];
$types = "";

// Product filter
if(isset($_GET['product_id']) && !empty($_GET['product_id'])) {
    $where_clause .= " AND r.product_id = ?";
    $params[] = intval($_GET['product_id']);
    $types .= "i";
}

// Status filter
if(isset($_GET['status']) && !empty($_GET['status'])) {
    $where_clause .= " AND r.status = ?";
    $params[] = $_GET['status'];
    $types .= "s";
}

// Date filter
if(isset($_GET['date']) && !empty($_GET['date'])) {
    $where_clause .= " AND DATE(r.pickup_date) = ?";
    $params[] = $_GET['date'];
    $types .= "s";
}

// Get all reservations with filters
$query = "SELECT r.*, p.name as product_name, p.image, p.stock, p.current_stock, p.reserved_stock,
                 p.available_stock, r.selected_color, r.selected_size, b.brand_name
          FROM reservations r 
          JOIN products p ON r.product_id = p.id 
          LEFT JOIN brands b ON p.brand_id = b.id
          WHERE $where_clause 
          ORDER BY 
            CASE r.status 
                WHEN 'PENDING' THEN 1 
                WHEN 'CONFIRMED' THEN 2 
                ELSE 3 
            END, 
            r.pickup_date ASC, 
            r.created_at DESC";

$stmt = mysqli_prepare($conn, $query);
if(!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Store all rows
$reservations = [];
while($row = mysqli_fetch_assoc($result)) {
    $reservations[] = $row;
}

// Get products for filter dropdown
$products_query = "SELECT p.id, p.name, p.product_code, 
                          COUNT(r.id) as reservation_count,
                          SUM(CASE WHEN r.status = 'PENDING' THEN 1 ELSE 0 END) as pending_count
                   FROM products p
                   LEFT JOIN reservations r ON p.id = r.product_id
                   WHERE p.status = 'active'
                   GROUP BY p.id
                   ORDER BY p.name";
$products_result = mysqli_query($conn, $products_query);

// Get statistics
$stats_query = "SELECT 
                   COUNT(*) as total,
                   SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                   SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed,
                   SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled,
                   SUM(CASE WHEN status = 'CONFIRMED' THEN quantity ELSE 0 END) as total_reserved_units
                FROM reservations";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations - KB Riders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .status-badge.pending {
            background-color: #ffc107;
            color: #000;
        }
        .status-badge.confirmed {
            background-color: #28a745;
            color: #fff;
        }
        .status-badge.cancelled {
            background-color: #dc3545;
            color: #fff;
        }
        .stock-info {
            font-size: 0.85rem;
        }
        .stock-available {
            color: #28a745;
        }
        .stock-reserved {
            color: #ffc107;
        }
        .stock-low {
            color: #dc3545;
        }
        .notification-badge {
            background: red;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.7rem;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header with Stats -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>
                        <i class="bi bi-calendar-check"></i> Reservation Management
                    </h1>
                    <div class="d-flex gap-2">
                        <a href="products.php" class="btn btn-outline-primary">
                            <i class="bi bi-box"></i> Products
                        </a>
                        <a href="inventory.php" class="btn btn-outline-info">
                            <i class="bi bi-clipboard-data"></i> Inventory
                        </a>
                        <a href="reports.php?type=reservations" class="btn btn-outline-secondary">
                            <i class="bi bi-file-earmark-bar-graph"></i> Reports
                        </a>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Reservations</h5>
                                <h2><?php echo $stats['total'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h5 class="card-title">Pending</h5>
                                <h2><?php echo $stats['pending'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Confirmed</h5>
                                <h2><?php echo $stats['confirmed'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">Reserved Units</h5>
                                <h2><?php echo $stats['total_reserved_units'] ?? 0; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Messages -->
                <?php if(isset($_SESSION['message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill"></i>
                        <?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-funnel"></i> Filter Reservations
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Filter by Product</label>
                                <select name="product_id" class="form-select">
                                    <option value="">All Products</option>
                                    <?php while($product = mysqli_fetch_assoc($products_result)): ?>
                                        <option value="<?php echo $product['id']; ?>" 
                                            <?php echo (isset($_GET['product_id']) && $_GET['product_id'] == $product['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($product['name']); ?>
                                            (<?php echo $product['pending_count']; ?> pending)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="PENDING" <?php echo (isset($_GET['status']) && $_GET['status'] == 'PENDING') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="CONFIRMED" <?php echo (isset($_GET['status']) && $_GET['status'] == 'CONFIRMED') ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="CANCELLED" <?php echo (isset($_GET['status']) && $_GET['status'] == 'CANCELLED') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Pickup Date</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $_GET['date'] ?? ''; ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Apply Filters
                                </button>
                            </div>
                            <?php if(isset($_GET['product_id']) || isset($_GET['status']) || isset($_GET['date'])): ?>
                            <div class="col-12">
                                <a href="reservations.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Clear Filters
                                </a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Reservations Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-list-task"></i> Reservations List
                        </h5>
                        <span class="badge bg-secondary"><?php echo count($reservations); ?> records</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Ticket No.</th>
                                        <th>Product</th>
                                        <th>Brand</th>
                                        <th>Customer</th>
                                        <th>Qty</th>
                                        <th>Color/Size</th>
                                        <th>Phone</th>
                                        <th>Pickup Date</th>
                                        <th>Available Stock</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($reservations as $row): ?>
                                    <?php 
                                    $reservation_qty = isset($row['quantity']) && $row['quantity'] > 0 ? $row['quantity'] : 1; 
                                    
                                    // For PENDING reservations, available stock should include this reservation
                                    if($row['status'] == 'PENDING') {
                                        $available_stock = ($row['available_stock'] ?? $row['stock'] ?? 0) + $reservation_qty;
                                    } else {
                                        $available_stock = $row['available_stock'] ?? $row['stock'] ?? 0;
                                    }
                                    
                                    $reserved_stock = $row['reserved_stock'] ?? 0;
                                    $stock_class = $available_stock <= 5 ? 'text-danger' : 'text-success';
                                    $status = strtolower($row['status']);
                                    $can_confirm = ($row['status'] == 'PENDING' && $available_stock >= $reservation_qty);
                                    ?>
                                    <tr class="status-<?php echo $row['status']; ?>">
                                        <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if($row['image']): ?>
                                                <img src="uploads/<?php echo $row['image']; ?>" 
                                                     alt="<?php echo $row['product_name']; ?>" 
                                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; margin-right: 8px;">
                                                <?php endif; ?>
                                                <?php echo $row['product_name']; ?>
                                            </div>
                                        </td>
                                        <td><?php echo $row['brand_name'] ?? 'N/A'; ?></td>
                                        <td><?php echo $row['customer_name']; ?></td>
                                        <td><span class="badge bg-primary"><?php echo $reservation_qty; ?></span></td>
                                        <td>
                                            <?php if(!empty($row['selected_color'])): ?>
                                                <span class="badge" style="background-color: <?php echo strtolower($row['selected_color']); ?>; color: white;">
                                                    <?php echo $row['selected_color']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if(!empty($row['selected_size'])): ?>
                                                <span class="badge bg-secondary"><?php echo $row['selected_size']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $row['phone']; ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($row['pickup_date'])); ?>
                                            <?php if(strtotime($row['pickup_date']) < time() && $row['status'] == 'CONFIRMED'): ?>
                                                <br><small class="text-danger">Overdue</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="stock-info">
                                                <span class="<?php echo $stock_class; ?> fw-bold">
                                                    <?php echo $available_stock; ?> units
                                                </span>
                                                <?php if($row['status'] == 'PENDING'): ?>
                                                    <br><small class="text-warning">(Includes this reservation)</small>
                                                <?php endif; ?>
                                                <?php if($reserved_stock > 0 && $row['status'] != 'PENDING'): ?>
                                                    <br><small class="text-warning"><?php echo $reserved_stock; ?> reserved total</small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status; ?>">
                                                <?php echo $row['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <!-- View Button -->
                                                <button type="button" class="btn btn-sm btn-info" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#viewModal<?php echo $row['id']; ?>"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                
                                                <?php if($row['status'] == 'PENDING'): ?>
                                                    <?php if($can_confirm): ?>
                                                        <a href="?action=confirm&id=<?php echo $row['id']; ?>" 
                                                           class="btn btn-sm btn-success"
                                                           onclick="return confirm('Confirm this reservation? This will deduct <?php echo $reservation_qty; ?> unit(s) from available stock.')"
                                                           title="Confirm Reservation">
                                                            <i class="bi bi-check-circle"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <button type="button" 
                                                                class="btn btn-sm btn-secondary" 
                                                                disabled
                                                                title="Insufficient stock (Available: <?php echo $available_stock; ?>)">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="?action=cancel&id=<?php echo $row['id']; ?>" 
                                                       class="btn btn-sm btn-danger"
                                                       onclick="return confirm('Cancel this reservation? This will notify the customer.')"
                                                       title="Cancel Reservation">
                                                        <i class="bi bi-x-circle"></i>
                                                    </a>
                                                <?php elseif($row['status'] == 'CONFIRMED'): ?>
                                                    <a href="?action=cancel&id=<?php echo $row['id']; ?>" 
                                                       class="btn btn-sm btn-warning"
                                                       onclick="return confirm('Cancel this confirmed reservation? This will restore <?php echo $reservation_qty; ?> unit(s) to available stock and notify the customer.')"
                                                       title="Cancel & Restock">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <!-- Link to Product -->
                                                <a href="products.php?view=<?php echo $row['product_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="View Product">
                                                    <i class="bi bi-box"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if(empty($reservations)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4">
                                            <i class="bi bi-calendar-x" style="font-size: 2rem;"></i>
                                            <p class="mt-2">No reservations found</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Details Modals -->
    <?php foreach($reservations as $row): ?>
    <?php 
    $reservation_qty = isset($row['quantity']) && $row['quantity'] > 0 ? $row['quantity'] : 1; 
    
    if($row['status'] == 'PENDING') {
        $available_stock = ($row['available_stock'] ?? $row['stock'] ?? 0) + $reservation_qty;
    } else {
        $available_stock = $row['available_stock'] ?? $row['stock'] ?? 0;
    }
    
    $reserved_stock = $row['reserved_stock'] ?? 0;
    $total_stock = $available_stock + $reserved_stock;
    ?>
    <div class="modal fade" id="viewModal<?php echo $row['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-ticket-perforated"></i> 
                        Reservation Details - <?php echo $row['ticket_number']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 text-center">
                            <?php if($row['image']): ?>
                            <img src="uploads/<?php echo $row['image']; ?>" 
                                 class="img-fluid rounded mb-3" 
                                 alt="<?php echo $row['product_name']; ?>"
                                 style="max-height: 150px; object-fit: contain;">
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <span class="status-badge <?php echo strtolower($row['status']); ?>">
                                    <?php echo $row['status']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 40%;">Ticket Number:</th>
                                    <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Product:</th>
                                    <td>
                                        <?php echo $row['product_name']; ?>
                                        <br>
                                        <small class="text-muted">Brand: <?php echo $row['brand_name'] ?? 'N/A'; ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Quantity:</th>
                                    <td><span class="badge bg-primary"><?php echo $reservation_qty; ?> pcs</span></td>
                                </tr>
                                <tr>
                                    <th>Color/Size:</th>
                                    <td>
                                        <?php if(!empty($row['selected_color'])): ?>
                                            <span class="badge" style="background-color: <?php echo strtolower($row['selected_color']); ?>; color: white;">
                                                <?php echo $row['selected_color']; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if(!empty($row['selected_size'])): ?>
                                            <span class="badge bg-secondary"><?php echo $row['selected_size']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Customer:</th>
                                    <td><?php echo $row['customer_name']; ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo $row['phone']; ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?php echo $row['email'] ?? 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Pickup Date:</th>
                                    <td><?php echo date('F d, Y', strtotime($row['pickup_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Reservation Date:</th>
                                    <td><?php echo date('F d, Y h:i A', strtotime($row['created_at'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Stock Status:</th>
                                    <td>
                                        <div>
                                            <span class="<?php echo $available_stock <= 5 ? 'text-danger' : 'text-success'; ?>">
                                                <i class="bi bi-box"></i> Available: <?php echo $available_stock; ?>
                                            </span>
                                        </div>
                                        <?php if($reserved_stock > 0): ?>
                                            <div>
                                                <span class="text-warning">
                                                    <i class="bi bi-lock"></i> Reserved: <?php echo $reserved_stock; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="text-info">
                                                <i class="bi bi-grid"></i> Total: <?php echo $total_stock; ?>
                                            </span>
                                        </div>
                                        <?php if($row['status'] == 'PENDING'): ?>
                                            <div class="alert alert-warning mt-2 mb-0 py-1 px-2">
                                                <small><i class="bi bi-info-circle"></i> This pending reservation is included in available stock (<?php echo $reservation_qty; ?> unit reserved)</small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if(!empty($row['notes'])): ?>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <strong>Notes:</strong><br>
                                <?php echo nl2br($row['notes']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <?php if($row['status'] == 'PENDING' && $available_stock >= $reservation_qty): ?>
                        <a href="?action=confirm&id=<?php echo $row['id']; ?>" 
                           class="btn btn-success"
                           onclick="return confirm('Confirm this reservation? This will deduct <?php echo $reservation_qty; ?> unit(s) from available stock.')">
                            <i class="bi bi-check-circle"></i> Confirm Reservation
                        </a>
                    <?php elseif($row['status'] == 'PENDING' && $available_stock < $reservation_qty): ?>
                        <button type="button" class="btn btn-secondary" disabled>
                            <i class="bi bi-exclamation-triangle"></i> Insufficient Stock (Available: <?php echo $available_stock; ?>)
                        </button>
                    <?php endif; ?>
                    
                    <?php if($row['status'] != 'CANCELLED'): ?>
                        <a href="?action=cancel&id=<?php echo $row['id']; ?>" 
                           class="btn btn-danger"
                           onclick="return confirm('Cancel this reservation? This will notify the customer.')">
                            <i class="bi bi-x-circle"></i> Cancel Reservation
                        </a>
                    <?php endif; ?>
                    
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>