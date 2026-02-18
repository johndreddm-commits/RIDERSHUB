<?php
session_start();
require_once 'config.php';

// Handle report generation
if(isset($_POST['generate_report'])) {
    $report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
    $end_date = mysqli_real_escape_string($conn, $_POST['end_date']);
    
    // Build query based on report type
    if($report_type == 'inventory') {
        $query = "SELECT p.id, p.name, p.product_code, b.brand_name, p.price, 
                         COALESCE(i.quantity, 0) as stock, i.min_stock,
                         p.status, p.updated_at
                  FROM products p
                  LEFT JOIN brands b ON p.brand_id = b.id
                  LEFT JOIN inventory i ON p.id = i.product_id
                  WHERE p.updated_at BETWEEN '$start_date' AND '$end_date 23:59:59'
                  ORDER BY p.name";
    } elseif($report_type == 'reservations') {
        $query = "SELECT r.ticket_number, r.customer_name, r.phone, p.name as product_name,
                         r.pickup_date, r.status, r.created_at
                  FROM reservations r
                  JOIN products p ON r.product_id = p.id
                  WHERE r.created_at BETWEEN '$start_date' AND '$end_date 23:59:59'
                  ORDER BY r.created_at DESC";
    } elseif($report_type == 'sales') {
        $query = "SELECT o.id, o.product_name, o.quantity, o.total_amount, 
                         o.status, o.created_at
                  FROM orders o
                  WHERE o.created_at BETWEEN '$start_date' AND '$end_date 23:59:59'
                  AND o.status = 'delivered'
                  ORDER BY o.created_at DESC";
    }
    
    $result = mysqli_query($conn, $query);
    $report_data = [];
    while($row = mysqli_fetch_assoc($result)) {
        $report_data[] = $row;
    }
    
    // Store report data in session for PDF generation
    $_SESSION['report_data'] = $report_data;
    $_SESSION['report_type'] = $report_type;
    $_SESSION['start_date'] = $start_date;
    $_SESSION['end_date'] = $end_date;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - KB Riders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&display=swap" rel="stylesheet">
     <link rel="stylesheet" href="dashboard.css">
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">Generate Reports</h1>

                <!-- Report Form -->
                <div class="card mb-4 report-card">
                    <div class="card-header">
                        <h5 class="mb-0">Report Parameters</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="reportForm">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="report_type" class="form-label">Report Type</label>
                                        <select class="form-select" id="report_type" name="report_type" required>
                                            <option value="">Select Report Type</option>
                                            <option value="inventory">Inventory Report</option>
                                            <option value="reservations">Reservations Report</option>
                                            <option value="sales">Sales Report</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" required>
                                    </div>
                                </div>
                            </div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="generate_report" class="btn btn-primary">
                                    <i class="bi bi-file-earmark-text"></i> Generate Report
                                </button>
                                <button type="button" class="btn btn-success" id="exportPdf">
                                    <i class="bi bi-file-pdf"></i> Export to PDF
                                </button>
                                <button type="button" class="btn btn-secondary no-print" onclick="window.print()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Report Display -->
                <?php if(isset($report_data) && !empty($report_data)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <?php 
                            $report_titles = [
                                'inventory' => 'Inventory Report',
                                'reservations' => 'Reservations Report',
                                'sales' => 'Sales Report'
                            ];
                            echo $report_titles[$report_type];
                            ?>
                            <small class="text-muted ms-2">
                                (<?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>)
                            </small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped report-table">
                                <thead>
                                    <tr>
                                        <?php if($report_type == 'inventory'): ?>
                                            <th>ID</th>
                                            <th>Product Code</th>
                                            <th>Product Name</th>
                                            <th>Brand</th>
                                            <th>Price</th>
                                            <th>Stock</th>
                                            <th>Min Stock</th>
                                            <th>Status</th>
                                            <th>Last Updated</th>
                                        <?php elseif($report_type == 'reservations'): ?>
                                            <th>Ticket No.</th>
                                            <th>Customer</th>
                                            <th>Phone</th>
                                            <th>Product</th>
                                            <th>Pickup Date</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                        <?php elseif($report_type == 'sales'): ?>
                                            <th>Order ID</th>
                                            <th>Product</th>
                                            <th>Quantity</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($report_data as $row): ?>
                                    <tr>
                                        <?php if($report_type == 'inventory'): ?>
                                            <td><?php echo $row['id']; ?></td>
                                            <td><?php echo $row['product_code'] ?: 'N/A'; ?></td>
                                            <td><?php echo $row['name']; ?></td>
                                            <td><?php echo $row['brand_name'] ?: 'N/A'; ?></td>
                                            <td>₱<?php echo number_format($row['price'], 2); ?></td>
                                            <td><?php echo $row['stock']; ?></td>
                                            <td><?php echo $row['min_stock']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $row['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                    <?php echo ucfirst($row['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($row['updated_at'])); ?></td>
                                        <?php elseif($report_type == 'reservations'): ?>
                                            <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                                            <td><?php echo $row['customer_name']; ?></td>
                                            <td><?php echo $row['phone']; ?></td>
                                            <td><?php echo $row['product_name']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($row['pickup_date'])); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $row['status'] == 'CONFIRMED' ? 'success' : 
                                                         ($row['status'] == 'PENDING' ? 'warning' : 'danger'); 
                                                ?>">
                                                    <?php echo $row['status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                                        <?php elseif($report_type == 'sales'): ?>
                                            <td><?php echo $row['id']; ?></td>
                                            <td><?php echo $row['product_name']; ?></td>
                                            <td><?php echo $row['quantity']; ?></td>
                                            <td>₱<?php echo number_format($row['total_amount'], 2); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $row['status'] == 'delivered' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($row['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Set default dates
        document.getElementById('start_date').valueAsDate = new Date();
        document.getElementById('end_date').valueAsDate = new Date();

        // PDF Export
        document.getElementById('exportPdf').addEventListener('click', function() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            
            // Report title
            doc.setFontSize(16);
            doc.text('KB Riders Inventory Report', 14, 15);
            doc.setFontSize(12);
            doc.text('Generated: ' + new Date().toLocaleDateString(), 14, 22);
            
            // Get table data
            const table = document.querySelector('.report-table');
            if(table) {
                const rows = table.querySelectorAll('tr');
                const data = [];
                
                // Get headers
                const headers = [];
                table.querySelectorAll('thead th').forEach(th => {
                    headers.push(th.textContent.trim());
                });
                
                // Get data rows
                table.querySelectorAll('tbody tr').forEach(tr => {
                    const rowData = [];
                    tr.querySelectorAll('td').forEach(td => {
                        // Remove badge HTML from status cells
                        let text = td.textContent.trim();
                        const badge = td.querySelector('.badge');
                        if(badge) {
                            text = badge.textContent.trim();
                        }
                        rowData.push(text);
                    });
                    data.push(rowData);
                });
                
                // Create table
                doc.autoTable({
                    head: [headers],
                    body: data,
                    startY: 30,
                    styles: { fontSize: 10 },
                    headStyles: { fillColor: [13, 110, 253] }
                });
                
                // Save PDF
                doc.save('KB_Riders_Report_' + new Date().toISOString().split('T')[0] + '.pdf');
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>