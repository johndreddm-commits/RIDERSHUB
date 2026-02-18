<?php
session_start();
require_once "config.php";

// IF ALREADY LOGGED IN
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $admin_id = trim($_POST['admin_id']);
    $password = trim($_POST['password']);

    if (empty($admin_id) || empty($password)) {
        $error = "❌ All fields are required";
    } else {

        $sql = "SELECT admin_id, password FROM admins WHERE admin_id = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("s", $admin_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc();

                if (password_verify($password, $row['password'])) {

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $row['admin_id'];
                    $_SESSION['role'] = 'admin';

                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error = "❌ Invalid password";
                }
            } else {
                $error = "❌ Admin not found";
            }
        } else {
            $error = "❌ Database error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login – KB Ridershub</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&family=Audiowide&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
<nav class="nav">
    <div class="nav-left">
        <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" class="nav-logo">
        <span class="nav-title">RIDERSHUB</span>
    </div>
</nav>

<div class="forms-container">
    <div class="form-box">
        <h2 class="form-title"><span class="admin-title">Admin</span> Login</h2>

        <?php if(!empty($error)): ?>
            <p style="color:#ff6666; text-align:center; font-size:13px;">
                <?= $error ?>
            </p>
        <?php endif; ?>

        <!-- ✅ FIXED ACTION -->
        <form method="POST" action="admin_login.php" class="form">

            <label><i class="fas fa-user-shield"></i> Admin ID</label>
            <input type="text" name="admin_id" required>

            <label><i class="fas fa-lock"></i> Password</label>
            <input type="password" name="password" required>

            <div class="checkbox-group">
                <input type="checkbox" id="remember">
                <label for="remember" style="font-size:12px;">Remember me</label>
            </div>

            <button class="btn" type="submit">LOGIN AS ADMIN</button>

            <div class="divider"><span>Warning</span></div>
            <p style="text-align:center; font-size:12px; color:#ff6666;">
                <i class="fas fa-exclamation-triangle"></i> Admin access only.
            </p>
        </form>
    </div>
</div>

</body>
</html>
