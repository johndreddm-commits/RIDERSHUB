<?php
session_start();
require_once "config.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $pass = $_POST['password'];
    
    // Check if passwords match (client-side already does this, but good for security)
    if ($_POST['password'] !== $_POST['confirm_password']) {
        die("<script>alert('Passwords do not match!'); window.location.href='register.php';</script>");
    }
    
    // Check if email already exists
    $check_sql = "SELECT id FROM users WHERE email = ?";
    if ($check_stmt = $conn->prepare($check_sql)) {
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            echo "<script>alert('Email already registered!'); window.location.href='login.php';</script>";
            $check_stmt->close();
            exit();
        }
        $check_stmt->close();
    }

    // Secure password hashing
    $hashed_password = password_hash($pass, PASSWORD_DEFAULT);

    // Prepare an insert statement with default 'user' role
    $sql = "INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, 'user')";
    
    if($stmt = $conn->prepare($sql)){
        $stmt->bind_param("sss", $fullname, $email, $hashed_password);

        if ($stmt->execute()) {
            // Auto-login after registration
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $stmt->insert_id; // Get the new user ID
            $_SESSION["fullname"] = $fullname;
            $_SESSION["email"] = $email;
            $_SESSION["role"] = 'user';
            
            echo "<script>alert('Registration successful!'); window.location.href='login.php';</script>";
        } else {
            echo "<script>alert('Something went wrong. Please try again.'); window.location.href='register.php';</script>";
        }
        $stmt->close();
    }
    $conn->close();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – KB Ridershub</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&family=Audiowide&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="nav">
        <div class="nav-left">
            <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" class="nav-logo" alt="Ridershub Logo">
            <span class="nav-title">RIDERSHUB</span>
        </div>
    </nav>

    <div class="forms-container">
        <div id="register-form" class="form-box">
            <h2 class="form-title">Create Account</h2>
            <form id="registerForm" class="form" action="register.php" method="POST">
                <label><i class="fas fa-user"></i> Full Name</label>
                <input type="text" id="fullname" name="fullname" placeholder="Enter your full name" required>

                <label><i class="fas fa-envelope"></i> Email</label>
                <input type="email" id="email" name="email" placeholder="Enter your email" required>

                <label><i class="fas fa-lock"></i> Password</label>
                <input type="password" id="password" name="password" placeholder="Create a password" required>

                <label><i class="fas fa-lock"></i> Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required>

                <button class="btn" type="submit" href="login.php">REGISTER</button>

                <p class="link">
                    Already have an account? <a href="login.php">Sign in</a>
                </p>
            </form>
        </div>
    </div>
    <div class="bottom-shape"></div>
    
    <script>
        document.getElementById("registerForm").addEventListener("submit", function (e) {
            const pass = document.getElementById("password").value;
            const confirmPass = document.getElementById("confirm_password").value;

            if (pass !== confirmPass) {
                e.preventDefault();
                alert("Passwords do not match!");
                return false;
            }
            
            // Additional password strength validation (optional)
            if (pass.length < 6) {
                e.preventDefault();
                alert("Password must be at least 6 characters long!");
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>