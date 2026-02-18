<?php
session_start();
require_once 'config.php';

// Handle actions
if(isset($_GET['action'])) {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    
    // Get reservation details before any action
    $reservation_query = "SELECT r.*, p.name as product_name, p.image, p.stock 
                          FROM reservations r 
                          JOIN products p ON r.product_id = p.id 
                          WHERE r.id = $id";
    $reservation_result = mysqli_query($conn, $reservation_query);
    $reservation = mysqli_fetch_assoc($reservation_result);
    
    if($_GET['action'] == 'confirm') {
        if($reservation['status'] != 'CONFIRMED') {
            $product_id = $reservation['product_id'];
            $product_query = "SELECT * FROM products WHERE id = $product_id";
            $product_result = mysqli_query($conn, $product_query);
            $product = mysqli_fetch_assoc($product_result);
            
            $reservation_quantity = isset($reservation['quantity']) ? $reservation['quantity'] : 1;
            
            if($product['stock'] >= $reservation_quantity) {
                mysqli_begin_transaction($conn);
                
                try {
                    $query = "UPDATE reservations SET status = 'CONFIRMED' WHERE id = $id";
                    mysqli_query($conn, $query);
                    
                    $deduct_query = "UPDATE products 
                                     SET stock = stock - $reservation_quantity, 
                                         quantity = quantity - $reservation_quantity 
                                     WHERE id = $product_id";
                    mysqli_query($conn, $deduct_query);
                    
                    $inv_deduct_query = "UPDATE inventory 
                                         SET quantity = quantity - $reservation_quantity 
                                         WHERE product_id = $product_id";
                    mysqli_query($conn, $inv_deduct_query);
                    
                    $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, created_at) 
                                  VALUES ($product_id, 'out', $reservation_quantity, NOW())";
                    mysqli_query($conn, $log_query);
                    
                    mysqli_commit($conn);
                    
                    $_SESSION['message'] = "Reservation confirmed successfully! $reservation_quantity unit(s) deducted from stock.";
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $_SESSION['error'] = "Error confirming reservation: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "Cannot confirm reservation: Not enough stock! Available: {$product['stock']}, Requested: $reservation_quantity";
            }
        } else {
            $_SESSION['message'] = "Reservation is already confirmed!";
        }
        
    } elseif($_GET['action'] == 'cancel') {
        if($reservation['status'] == 'CONFIRMED') {
            $product_id = $reservation['product_id'];
            $reservation_quantity = isset($reservation['quantity']) ? $reservation['quantity'] : 1;
            
            mysqli_begin_transaction($conn);
            
            try {
                $query = "UPDATE reservations SET status = 'CANCELLED' WHERE id = $id";
                mysqli_query($conn, $query);
                
                $restore_query = "UPDATE products 
                                 SET stock = stock + $reservation_quantity, 
                                     quantity = quantity + $reservation_quantity 
                                 WHERE id = $product_id";
                mysqli_query($conn, $restore_query);
                
                $inv_restore_query = "UPDATE inventory 
                                     SET quantity = quantity + $reservation_quantity 
                                     WHERE product_id = $product_id";
                mysqli_query($conn, $inv_restore_query);
                
                $log_query = "INSERT INTO inventory_logs (product_id, action, quantity, created_at) 
                              VALUES ($product_id, 'in', $reservation_quantity, NOW())";
                mysqli_query($conn, $log_query);
                
                mysqli_commit($conn);
                
                $_SESSION['message'] = "Reservation cancelled! $reservation_quantity unit(s) restored to stock.";
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $_SESSION['error'] = "Error cancelling reservation: " . $e->getMessage();
            }
        } else {
            $query = "UPDATE reservations SET status = 'CANCELLED' WHERE id = $id";
            mysqli_query($conn, $query);
            $_SESSION['message'] = "Reservation cancelled!";
        }
    }
    
    header("Location: reservations.php");
    exit();
}

// Get all reservations
$query = "SELECT r.*, p.name as product_name, p.image, p.stock, 
                 r.selected_color, r.selected_size 
          FROM reservations r 
          JOIN products p ON r.product_id = p.id 
          ORDER BY r.created_at DESC";
$result = mysqli_query($conn, $query);

// Store all rows in array para magamit sa modals sa baba
$reservations = [];
while($row = mysqli_fetch_assoc($result)) {
    $reservations[] = $row;
}
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
       
        
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">Reservation Management</h1>
                
                <?php if(isset($_SESSION['message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Ticket No.</th>
                                        <th>Product</th>
                                        <th>Customer</th>
                                        <th>Qty</th>
                                        <th>Color</th>
                                        <th>Size</th>
                                        <th>Phone</th>
                                        <th>Pickup Date</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($reservations as $row): ?>
                                    <?php 
                                    $reservation_qty = isset($row['quantity']) && $row['quantity'] > 0 ? $row['quantity'] : 1; 
                                    $stock_class = $row['stock'] <= 5 ? 'stock-low' : 'stock-ok';
                                    $status = strtolower($row['status']);
                                    ?>
                                    <tr class="status-<?php echo $row['status']; ?>">
                                        <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if($row['image']): ?>
                                                <img src="uploads/<?php echo $row['image']; ?>" 
                                                     alt="<?php echo $row['product_name']; ?>" 
                                                     style="width: 40px; height: 40px; object-fit: cover; margin-right: 8px;">
                                                <?php endif; ?>
                                                <?php echo $row['product_name']; ?>
                                            </div>
                                        </td>
                                        <td><?php echo $row['customer_name']; ?></td>
                                        <td><span class="badge bg-primary"><?php echo $reservation_qty; ?></span></td>
                                        <td>
                                            <?php if(!empty($row['selected_color'])): ?>
                                                <span class="color-dot" style="background-color: <?php echo strtolower($row['selected_color']); ?>;"></span>
                                                <?php echo $row['selected_color']; ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($row['selected_size'])): ?>
                                                <span class="badge bg-secondary"><?php echo $row['selected_size']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $row['phone']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($row['pickup_date'])); ?></td>
                                        <td>
                                            <span class="<?php echo $stock_class; ?>">
                                                <?php echo $row['stock']; ?> units
                                                <?php if($row['stock'] <= 5): ?>
                                                    <br><small class="text-danger">Low Stock!</small>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status; ?>">
                                                <?php echo $row['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <!-- ACTION BUTTONS - KAGAYA NG NASA KANANG IMAGE -->
                                            <div class="action-buttons">
                                                <!-- View Button -->
                                                <button type="button" class="btn btn-sm btn-info" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#viewModal<?php echo $row['id']; ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                
                                                <?php if($row['status'] == 'PENDING'): ?>
                                                    <a href="?action=confirm&id=<?php echo $row['id']; ?>" 
                                                       class="btn btn-sm btn-success"
                                                       onclick="return confirm('Confirm this reservation? This will deduct <?php echo $reservation_qty; ?> unit(s) from stock.')">
                                                        <i class="bi bi-check-circle"></i> Confirm
                                                    </a>
                                                    <a href="?action=cancel&id=<?php echo $row['id']; ?>" 
                                                       class="btn btn-sm btn-danger"
                                                       onclick="return confirm('Cancel this reservation?')">
                                                        <i class="bi bi-x-circle"></i> Cancel
                                                    </a>
                                                <?php elseif($row['status'] == 'CONFIRMED'): ?>
                                                    <!-- CANCEL & RESTOCK BUTTON - TULAD NG NASA KANANG IMAGE -->
                                                    <a href="?action=cancel&id=<?php echo $row['id']; ?>" 
                                                       class="btn btn-sm btn-warning"
                                                       onclick="return confirm('Cancel this confirmed reservation? This will restore <?php echo $reservation_qty; ?> unit(s) to stock.')">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Cancel & Restock
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- LAHAT NG MODALS - NASA LABAS NG TABLE -->
    <?php foreach($reservations as $row): ?>
    <?php 
    $reservation_qty = isset($row['quantity']) && $row['quantity'] > 0 ? $row['quantity'] : 1; 
    $stock_class = $row['stock'] <= 5 ? 'stock-low' : 'stock-ok';
    $status = strtolower($row['status']);
    ?>
    <div class="modal fade" id="viewModal<?php echo $row['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reservation Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 text-center">
                            <?php if($row['image']): ?>
                            <img src="uploads/<?php echo $row['image']; ?>" 
                                 class="img-fluid rounded mb-3" 
                                 alt="<?php echo $row['product_name']; ?>"
                                 style="max-height: 150px;">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <table class="table table-sm">
                                <tr>
                                    <th>Ticket Number:</th>
                                    <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Product:</th>
                                    <td><?php echo $row['product_name']; ?></td>
                                </tr>
                                <tr>
                                    <th>Quantity:</th>
                                    <td><span class="badge bg-primary"><?php echo $reservation_qty; ?> pcs</span></td>
                                </tr>
                                <?php if(!empty($row['selected_color'])): ?>
                                <tr>
                                    <th>Color:</th>
                                    <td>
                                        <span class="color-dot" style="background-color: <?php echo strtolower($row['selected_color']); ?>;"></span>
                                        <?php echo $row['selected_color']; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php if(!empty($row['selected_size'])): ?>
                                <tr>
                                    <th>Size:</th>
                                    <td><span class="badge bg-info"><?php echo $row['selected_size']; ?></span></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>Customer:</th>
                                    <td><?php echo $row['customer_name']; ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo $row['phone']; ?></td>
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
                                    <th>Stock Available:</th>
                                    <td class="<?php echo $stock_class; ?>"><?php echo $row['stock']; ?> units</td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <span class="status-badge <?php echo $status; ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

