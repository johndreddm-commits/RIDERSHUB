<?php
session_start();
require_once "config.php";

// Initialize error message
$error_message = "";

// Check if user is already logged in
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: index.php");
    exit;
}

// Forgot password functionality
if (isset($_POST['forgot_password'])) {
    $email = trim($_POST['email']);
    
    // Check if email exists
    $sql = "SELECT id, fullname FROM users WHERE email = ?";
    if($stmt = $conn->prepare($sql)){
        $stmt->bind_param("s", $email);
        
        if($stmt->execute()){
            $stmt->store_result();
            
            if($stmt->num_rows == 1){
                $stmt->bind_result($id, $fullname);
                $stmt->fetch();
                
                // Generate reset token (valid for 1 hour)
                $reset_token = bin2hex(random_bytes(32));
                $expiry_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store reset token in database
                $update_sql = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?";
                if($update_stmt = $conn->prepare($update_sql)){
                    $update_stmt->bind_param("ssi", $reset_token, $expiry_time, $id);
                    
                    if($update_stmt->execute()){
                        // Send reset email (in production, use proper email sending)
                        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $reset_token;
                        
                        // For demo purposes, we'll show the link. In production, send via email.
                        $_SESSION['reset_link'] = $reset_link;
                        $_SESSION['reset_email'] = $email;
                        
                        header("location: login.php?sent=1");
                        exit();
                    }
                    $update_stmt->close();
                }
            } else {
                $_SESSION['error'] = "No account found with that email address.";
                header("location: login.php?error=1");
                exit();
            }
        }
        $stmt->close();
    }
}

// Regular login functionality
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Select the role along with other details
    $sql = "SELECT id, fullname, email, password, role FROM users WHERE email = ?";
    
    if($stmt = $conn->prepare($sql)){
        $stmt->bind_param("s", $email);
        
        if($stmt->execute()){
            $stmt->store_result();
            
            if($stmt->num_rows == 1){                    
                $stmt->bind_result($id, $fullname, $email, $hashed_password, $role);
                if($stmt->fetch()){
                    if(password_verify($password, $hashed_password)){
                        // Store variables in session
                        $_SESSION["loggedin"] = true;
                        $_SESSION["id"] = $id;
                        $_SESSION["fullname"] = $fullname;
                        $_SESSION["email"] = $email;
                        $_SESSION["role"] = $role; // Store the role (admin or user)                            
                        
                        // Redirect based on role
                        if($role === 'user'){
                            header("location: index.php");
                        } else {
                            header("location: Landingpage.html");
                        }
                        exit();
                    } else {
                        // Incorrect password - stay on login page with error
                        $error_message = "Invalid password. Please try again.";
                    }
                }
            } else {
                // No account found
                $error_message = "No account found with that email address.";
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>KB Ridershub - Premium Motorcycle Gear</title>

    <!-- FONTS -->
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&family=Russo+One&family=Audiowide&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0a0a0a;
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            overflow-x: hidden;
        }

        /* ===== NAVIGATION (EXACTLY AS PROVIDED) ===== */
        .nav {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 20px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(15px);
            border-bottom: 2px solid #ff0000;
            box-shadow: 0 5px 30px rgba(255, 0, 0, 0.2);
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .nav-left:hover {
            transform: scale(1.02);
        }

        .nav-logo {
            width: 65px;
            height: 65px;
            filter: drop-shadow(0 0 15px rgba(255, 0, 0, 0.8));
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .nav-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 3px;
            background: linear-gradient(90deg, #fff, #ff0000, #fff);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shine 3s linear infinite;
        }

        @keyframes shine {
            to { background-position: 200% center; }
        }

        /* SIGN IN BUTTON (EXACTLY AS PROVIDED) */
        .sign-in-btn {
            background: red;
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            border: 2px solid red;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .sign-in-btn i {
            font-size: 1.1rem;
        }

        .sign-in-btn:hover {
            background: transparent;
            color: red;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 0, 0, 0.3);
        }

        /* ===== HERO SECTION (EXACTLY AS PROVIDED) ===== */
        .hero {
            position: relative;
            width: 100%;
            min-height: 100vh;
            overflow: hidden;
            margin-top: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.7);
            transform: scale(1.1);
            animation: zoomOut 8s ease-out forwards;
            z-index: 0;
        }

        @keyframes zoomOut {
            to { transform: scale(1); }
        }

        .hero-overlay {
            position: relative;
            z-index: 2;
            text-align: center;
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 0, 0, 0.3);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            animation: fadeInUp 1s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-overlay h1 {
            font-family: 'Russo One', sans-serif;
            font-size: clamp(2.5rem, 8vw, 5rem);
            font-weight: 900;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 4px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .kb {
            color: red;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { text-shadow: 0 0 20px red; }
            50% { text-shadow: 0 0 40px red, 0 0 60px red; }
        }

        .hero-overlay p {
            font-size: clamp(1rem, 3vw, 1.3rem);
            color: #fff;
            margin-bottom: 30px;
            font-weight: 400;
            letter-spacing: 2px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        }

        .hero-search {
            display: flex;
            max-width: 600px;
            margin: 0 auto;
            gap: 10px;
        }

        .search-input {
            flex: 1;
            padding: 18px 25px;
            border: none;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
            color: white;
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            border: 1px solid rgba(255, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: red;
            background: rgba(0, 0, 0, 0.5);
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.3);
        }

        .search-input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .search-btn {
            padding: 18px 40px;
            border: none;
            border-radius: 50px;
            background: red;
            color: white;
            font-weight: 700;
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid red;
        }

        .search-btn:hover {
            background: transparent;
            color: red;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 0, 0, 0.3);
        }

        .hero-bottom-shape {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 100px;
            background: linear-gradient(to top, #0a0a0a, transparent);
            z-index: 1;
        }

        /* ===== LOGIN FORM SECTION (HIDDEN INITIALLY) ===== */
        .login-section {
            padding: 80px 20px;
            background: #0a0a0a;
            position: relative;
            display: none; /* Hidden by default */
        }

        .login-section.active {
            display: block; /* Show when active class is added */
        }

        .login-container {
            max-width: 500px;
            margin: 0 auto;
            background: #111;
            border-radius: 20px;
            padding: 40px;
            border: 1px solid #222;
            box-shadow: 0 10px 30px rgba(255, 0, 0, 0.1);
        }

        .login-container h2 {
            font-family: 'Audiowide', sans-serif;
            font-size: 2rem;
            color: red;
            margin-bottom: 10px;
            text-align: center;
        }

        .login-subtitle {
            text-align: center;
            color: #888;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }

        /* Error message styles */
        .error-message {
            background: rgba(255, 0, 0, 0.1);
            color: #ff4444;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ff4444;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        .error-message i {
            font-size: 18px;
        }
        
        /* Success message styles */
        .success-message {
            background: rgba(0, 255, 0, 0.1);
            color: #00ff00;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #00ff00;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        .success-message i {
            font-size: 18px;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            color: #ccc;
            margin-bottom: 8px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .form-group label i {
            color: red;
            margin-right: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            color: white;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: red;
            box-shadow: 0 0 15px rgba(255, 0, 0, 0.2);
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            background: red;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .login-btn:hover {
            background: #cc0000;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 0, 0, 0.3);
        }

        .admin-login-btn {
            display: block;
            width: 100%;
            padding: 15px;
            background: #111;
            color: white;
            text-decoration: none;
            text-align: center;
            border: 1px solid #444;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 15px;
            transition: all 0.3s ease;
        }

        .admin-login-btn:hover {
            background: #222;
            border-color: red;
            transform: translateY(-2px);
        }

        .admin-login-btn i {
            color: red;
            margin-right: 8px;
        }

        .form-footer {
            text-align: center;
            margin-top: 20px;
            color: #888;
        }

        .form-footer a {
            color: red;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .form-footer a:hover {
            color: #ff4444;
            text-decoration: underline;
        }

        .forgot-password {
            text-align: center;
            margin-top: 15px;
            cursor: pointer;
            color: #888;
            transition: color 0.3s;
            display: inline-block;
            width: 100%;
        }

        .forgot-password:hover {
            color: red;
            text-decoration: underline;
        }

        .forgot-password i {
            margin-right: 5px;
            color: red;
        }

        /* ===== FEATURED SECTION ===== */
        .featured-section {
            padding: 80px 20px;
            background: #0a0a0a;
        }

        .section-title {
            text-align: center;
            font-family: 'Audiowide', sans-serif;
            font-size: 2.5rem;
            color: red;
            margin-bottom: 50px;
            text-transform: uppercase;
            letter-spacing: 3px;
        }

        .featured-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .feature-card {
            background: #111;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            border: 1px solid #222;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            border-color: red;
            transform: translateY(-10px);
            box-shadow: 0 10px 30px rgba(255, 0, 0, 0.2);
        }

        .feature-card i {
            font-size: 3.5rem;
            color: red;
            margin-bottom: 20px;
        }

        .feature-card h3 {
            font-size: 1.3rem;
            color: red;
            margin-bottom: 15px;
        }

        .feature-card p {
            color: #888;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        /* ===== CTA SECTION ===== */
        .cta-section {
            padding: 100px 20px;
            background: linear-gradient(135deg, #ff0000, #cc0000);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 50%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .cta-content {
            position: relative;
            z-index: 2;
            max-width: 800px;
            margin: 0 auto;
        }

        .cta-content h2 {
            font-family: 'Audiowide', sans-serif;
            font-size: 2.5rem;
            color: white;
            margin-bottom: 20px;
        }

        .cta-content p {
            font-size: 1.2rem;
            color: rgba(255,255,255,0.9);
            margin-bottom: 30px;
        }

        .cta-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .cta-btn {
            display: inline-block;
            padding: 16px 40px;
            border-radius: 50px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 1.1rem;
            letter-spacing: 1px;
        }

        .cta-btn.primary {
            background: white;
            color: red;
            border: 2px solid white;
        }

        .cta-btn.primary:hover {
            background: transparent;
            color: white;
            transform: translateY(-3px);
        }

        .cta-btn.secondary {
            background: transparent;
            color: white;
            border: 2px solid white;
        }

        .cta-btn.secondary:hover {
            background: white;
            color: red;
            transform: translateY(-3px);
        }

        /* ===== FOOTER ===== */
        .footer {
            background: #111;
            border-top: 2px solid red;
            padding: 60px 20px 20px;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-col {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-logo img {
            width: 50px;
            height: 50px;
            filter: drop-shadow(0 0 10px rgba(255,0,0,0.5));
        }

        .footer-brand {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.3rem;
            color: red;
        }

        .footer-description {
            color: #888;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .social-links {
            display: flex;
            gap: 15px;
        }

        .social-link {
            width: 40px;
            height: 40px;
            background: rgba(255,0,0,0.1);
            border: 1px solid rgba(255,0,0,0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .social-link:hover {
            background: red;
            transform: translateY(-3px);
        }

        .footer-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.1rem;
            color: red;
            margin-bottom: 10px;
        }

        .footer-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .footer-links a {
            color: #888;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-links a:hover {
            color: red;
            transform: translateX(5px);
        }

        .footer-links a i {
            font-size: 0.8rem;
            color: red;
        }

        .footer-contact {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .footer-contact li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #888;
            font-size: 0.9rem;
        }

        .footer-contact li i {
            color: red;
            width: 20px;
        }

        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            padding-top: 20px;
            border-top: 1px solid #222;
        }

        .copyright {
            color: #666;
            font-size: 0.9rem;
        }

        .footer-bottom-links {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .footer-bottom-links a {
            color: #888;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .footer-bottom-links a:hover {
            color: red;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            backdrop-filter: blur(10px);
        }
        
        .modal-content {
            background: #111;
            margin: 10% auto;
            padding: 40px;
            border: 2px solid red;
            border-radius: 20px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 0 30px rgba(255, 0, 0, 0.3);
            color: #fff;
            position: relative;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .close {
            color: #666;
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .close:hover {
            color: red;
        }
        
        .modal h2 {
            color: red;
            margin-bottom: 20px;
            text-align: center;
            font-family: 'Audiowide', sans-serif;
        }
        
        .modal label {
            color: #ccc;
            display: block;
            margin: 15px 0 5px;
            font-weight: 600;
        }
        
        .modal input {
            width: 100%;
            padding: 12px 15px;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #fff;
            font-size: 16px;
            font-family: 'Orbitron', sans-serif;
        }

        .modal input:focus {
            outline: none;
            border-color: red;
        }
        
        .modal .btn {
            width: 100%;
            margin-top: 20px;
            padding: 15px;
            background: red;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .modal .btn:hover {
            background: #cc0000;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 0, 0, 0.3);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .nav {
                padding: 15px 20px;
            }

            .nav-logo {
                width: 45px;
                height: 45px;
            }

            .nav-title {
                font-size: 20px;
            }

            .sign-in-btn {
                padding: 10px 20px;
                font-size: 0.9rem;
            }

            .hero-search {
                flex-direction: column;
            }

            .search-btn {
                width: 100%;
            }

            .login-container {
                padding: 30px 20px;
            }

            .cta-buttons {
                flex-direction: column;
            }

            .footer-grid {
                gap: 30px;
            }

            .footer-bottom {
                flex-direction: column;
                text-align: center;
            }

            .footer-bottom-links {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .hero-overlay {
                padding: 30px 20px;
            }

            .feature-card {
                padding: 30px 20px;
            }

            .cta-content h2 {
                font-size: 2rem;
            }
        }
                /* ===== LOADING ANIMATION FOR LOGIN BUTTON ===== */
        .login-btn.loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .login-btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: buttonSpin 0.8s ease-in-out infinite;
        }

        @keyframes buttonSpin {
            to { transform: rotate(360deg); }
        }

        /* ===== PAGE LOAD ANIMATIONS ===== */
        .hero {
            animation: heroFadeIn 1.5s ease;
        }

        @keyframes heroFadeIn {
            from {
                opacity: 0;
                transform: scale(1.1);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .hero-overlay {
            animation: slideUp 1s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== NAVIGATION ANIMATIONS ===== */
        .nav {
            transition: all 0.3s ease;
        }

        .nav.scrolled {
            padding: 10px 60px;
            background: rgba(0, 0, 0, 0.98);
            box-shadow: 0 5px 40px rgba(255, 0, 0, 0.3);
        }

        .nav.scrolled .nav-logo {
            width: 55px;
            height: 55px;
        }

        .nav.scrolled .nav-title {
            font-size: 24px;
        }

        .sign-in-btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .sign-in-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
            z-index: 0;
        }

        .sign-in-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .sign-in-btn i {
            position: relative;
            z-index: 1;
            transition: transform 0.3s ease;
        }

        .sign-in-btn:hover i {
            transform: rotate(360deg);
        }

        /* ===== LOGIN SECTION ANIMATIONS ===== */
        .login-section {
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .login-section.active {
            animation: loginSlideDown 0.5s ease;
        }

        @keyframes loginSlideDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-container {
            animation: containerFadeIn 0.8s ease;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(255, 0, 0, 0.2);
        }

        @keyframes containerFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .form-group {
            animation: formItemFade 0.5s ease forwards;
            opacity: 0;
            animation-delay: calc(0.1s * var(--i));
        }

        .form-group:nth-child(1) { --i: 1; }
        .form-group:nth-child(2) { --i: 2; }

        @keyframes formItemFade {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .form-group input {
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            transform: scale(1.02);
            border-color: red;
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.3);
        }

        .login-btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
            z-index: 0;
        }

        .login-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .login-btn i {
            position: relative;
            z-index: 1;
            transition: transform 0.3s ease;
        }

        .login-btn:hover i {
            transform: translateX(5px);
        }

        .admin-login-btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .admin-login-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 0, 0, 0.1);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
            z-index: 0;
        }

        .admin-login-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .admin-login-btn i {
            transition: transform 0.3s ease;
        }

        .admin-login-btn:hover i {
            transform: rotate(360deg);
        }

        .forgot-password {
            position: relative;
            transition: all 0.3s ease;
        }

        .forgot-password i {
            transition: transform 0.3s ease;
        }

        .forgot-password:hover i {
            transform: scale(1.2);
        }

        /* ===== FEATURED SECTION ANIMATIONS ===== */
        .featured-section {
            animation: fadeInUp 1s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .feature-card {
            animation: cardFadeIn 0.5s ease forwards;
            opacity: 0;
            animation-delay: calc(0.1s * var(--i));
            transition: all 0.3s ease;
        }

        .feature-card:nth-child(1) { --i: 1; }
        .feature-card:nth-child(2) { --i: 2; }
        .feature-card:nth-child(3) { --i: 3; }
        .feature-card:nth-child(4) { --i: 4; }

        @keyframes cardFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .feature-card i {
            transition: all 0.3s ease;
        }

        .feature-card:hover i {
            transform: scale(1.2) rotate(360deg);
            color: #ff4444;
        }

        .feature-card h3 {
            transition: color 0.3s ease;
        }

        .feature-card:hover h3 {
            color: #ff4444;
        }

        /* ===== CTA SECTION ANIMATIONS ===== */
        .cta-section {
            overflow: hidden;
        }

        .cta-section::before {
            animation: rotate 20s linear infinite;
        }

        .cta-content {
            animation: fadeIn 1.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .cta-btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .cta-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
            z-index: 0;
        }

        .cta-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .cta-btn i {
            transition: transform 0.3s ease;
        }

        .cta-btn:hover i {
            transform: rotate(360deg);
        }

        /* ===== FOOTER ANIMATIONS ===== */
        .footer-col {
            animation: footerFadeIn 0.5s ease forwards;
            opacity: 0;
            animation-delay: calc(0.1s * var(--i));
        }

        .footer-col:nth-child(1) { --i: 1; }
        .footer-col:nth-child(2) { --i: 2; }
        .footer-col:nth-child(3) { --i: 3; }
        .footer-col:nth-child(4) { --i: 4; }

        @keyframes footerFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .footer-logo {
            transition: transform 0.3s ease;
        }

        .footer-logo:hover {
            transform: scale(1.05);
        }

        .footer-logo img {
            transition: filter 0.3s ease;
        }

        .footer-logo:hover img {
            filter: drop-shadow(0 0 20px rgba(255, 0, 0, 0.8));
        }

        .social-link {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .social-link::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .social-link:hover::before {
            width: 80px;
            height: 80px;
        }

        .social-link:hover {
            transform: translateY(-5px) scale(1.1);
        }

        .social-link i {
            transition: transform 0.3s ease;
        }

        .social-link:hover i {
            transform: scale(1.2);
        }

        .footer-links a {
            transition: all 0.3s ease;
        }

        .footer-links a i {
            transition: transform 0.3s ease;
        }

        .footer-links a:hover i {
            transform: rotate(360deg);
        }

        .footer-contact li {
            transition: all 0.3s ease;
        }

        .footer-contact li:hover {
            transform: translateX(5px);
            color: #aaa;
        }

        .footer-contact li i {
            transition: transform 0.3s ease;
        }

        .footer-contact li:hover i {
            transform: scale(1.2);
        }

        .footer-bottom-links a {
            position: relative;
            transition: color 0.3s ease;
        }

        .footer-bottom-links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 1px;
            background: red;
            transition: width 0.3s ease;
        }

        .footer-bottom-links a:hover::after {
            width: 100%;
        }

        .copyright {
            transition: color 0.3s ease;
        }

        .copyright:hover {
            color: #888;
        }

        /* ===== MODAL ANIMATIONS ===== */
        .modal {
            animation: modalBgFade 0.3s ease;
        }

        @keyframes modalBgFade {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .modal-content {
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .close {
            transition: all 0.3s ease;
        }

        .close:hover {
            transform: rotate(90deg);
        }

        .modal .btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .modal .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .modal .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        /* ===== ERROR/SUCCESS MESSAGE ANIMATIONS ===== */
        .error-message,
        .success-message {
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== BACK TO TOP BUTTON ===== */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: red;
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            justify-content: center;
            align-items: center;
            font-size: 20px;
            z-index: 100;
            transition: all 0.3s ease;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-5px);
            }
        }

        .back-to-top:hover {
            background: #cc0000;
            transform: translateY(-5px) scale(1.1);
            box-shadow: 0 5px 20px rgba(255, 0, 0, 0.4);
        }

        .back-to-top:active {
            transform: translateY(-2px) scale(1.05);
        }
                /* ===== LOADING ANIMATION FOR SIGN IN BUTTON ===== */
        .sign-in-btn.loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .sign-in-btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: buttonSpin 0.8s ease-in-out infinite;
        }

        @keyframes buttonSpin {
            to { transform: rotate(360deg); }
        }

        /* ===== LOGIN FORM APPEARANCE ANIMATION ===== */
        .login-section {
            transition: opacity 0.5s ease, transform 0.5s ease;
            opacity: 0;
            transform: translateY(20px);
        }

        .login-section.active {
            opacity: 1;
            transform: translateY(0);
            animation: loginReveal 0.6s ease-out;
        }

        @keyframes loginReveal {
            0% {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            50% {
                opacity: 0.8;
                transform: translateY(-5px) scale(1.02);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* ===== LOGIN CONTAINER ENTRANCE ANIMATION ===== */
        .login-section.active .login-container {
            animation: containerPopIn 0.5s ease-out 0.2s both;
        }

        @keyframes containerPopIn {
            0% {
                opacity: 0;
                transform: scale(0.9);
            }
            70% {
                transform: scale(1.05);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* ===== FORM ELEMENTS STAGGERED ANIMATION ===== */
        .login-section.active .form-group {
            animation: formElementFade 0.4s ease-out forwards;
            opacity: 0;
            transform: translateX(-10px);
        }

        .login-section.active .form-group:nth-child(1) {
            animation-delay: 0.3s;
        }

        .login-section.active .form-group:nth-child(2) {
            animation-delay: 0.4s;
        }

        .login-section.active .login-btn {
            animation: formElementFade 0.4s ease-out 0.5s forwards;
            opacity: 0;
            transform: translateX(-10px);
        }

        .login-section.active .admin-login-btn {
            animation: formElementFade 0.4s ease-out 0.6s forwards;
            opacity: 0;
            transform: translateX(-10px);
        }

        .login-section.active .form-footer {
            animation: formElementFade 0.4s ease-out 0.7s forwards;
            opacity: 0;
            transform: translateX(-10px);
        }

        .login-section.active .forgot-password {
            animation: formElementFade 0.4s ease-out 0.8s forwards;
            opacity: 0;
            transform: translateX(-10px);
        }

        @keyframes formElementFade {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>

<!-- NAVIGATION -->
<nav class="nav">
    <div class="nav-left">
        <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" class="nav-logo" alt="Ridershub Logo">
        <span class="nav-title">RIDERSHUB</span>
    </div>
    <!-- Back to Top Button -->
<button class="back-to-top" id="backToTop" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

    <!-- SIGN IN BUTTON - Shows login form -->
    <a href="#" class="sign-in-btn" onclick="showLoginForm(); return false;">
        <i class="fas fa-sign-in-alt"></i> SIGN IN
    </a>
</nav>

<!-- HERO SECTION -->
<section class="hero" id="home">
    <!-- IMAGE HERO -->
    <img src="images/Untitled design.png" class="hero-image" alt="Motorcycle Hero">

    <!-- OVERLAY TEXT -->
    <div class="hero-overlay">
        <h1>
            <span class="kb">K</span>NOW YOUR <span class="kb">B</span>REAKS
        </h1>

        <p>When necessities meets your expectation</p>

        <div class="hero-search">
            <input type="text" placeholder="Search products..." class="search-input" id="searchInput">
            <button class="search-btn" onclick="handleSearch()">SEARCH</button>
        </div>
    </div>

    <!-- ANGLED SHAPE -->
    <div class="hero-bottom-shape"></div>
</section>

<!-- LOGIN SECTION (HIDDEN INITIALLY) -->
<section class="login-section" id="login">
    <div class="login-container">
        <h2>USER LOGIN</h2>
        <p class="login-subtitle">Sign in to access your account</p>
        
        <!-- Display error message if any -->
        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Display session error if any -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Display success message if any -->
        <?php if (isset($_GET['sent']) && $_GET['sent'] == 1): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                Password reset link has been sent to your email.
            </div>
        <?php endif; ?>
        
        <form action="login.php" method="POST">
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> Email Address</label>
                <input type="email" name="email" placeholder="Enter your email" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>

            <div class="form-group">
                <label><i class="fas fa-lock"></i> Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>

            <button type="submit" name="login" class="login-btn">
                <i class="fas fa-sign-in-alt"></i> LOGIN
            </button>

            <a href="admin_login.php" class="admin-login-btn">
                <i class="fas fa-user-shield"></i> ADMIN LOGIN
            </a>

            <div class="form-footer">
                Don't have an account? <a href="register.php">Register here</a>
            </div>
            
            <div class="forgot-password" onclick="openForgotPasswordModal()">
                <i class="fas fa-key"></i> Forgot Password?
            </div>
        </form>
    </div>
</section>

<!-- FEATURED SECTION -->
<section class="featured-section">
    <h2 class="section-title">WHY CHOOSE US</h2>
    <div class="featured-grid">
        <div class="feature-card">
            <i class="fas fa-helmet-safety"></i>
            <h3>Premium Quality</h3>
            <p>Top-tier helmets from world-renowned brands for maximum safety</p>
        </div>
        
        <div class="feature-card">
            <i class="fas fa-shield-hal met"></i>
            <h3>100% Authentic</h3>
            <p>Genuine products with manufacturer warranty and certification</p>
        </div>
        <div class="feature-card">
            <i class="fas fa-headset"></i>
            <h3>24/7 Support</h3>
            <p>Customer service ready to assist you anytime you need help</p>
        </div>
    </div>
</section>

<!-- CTA SECTION -->
<section class="cta-section">
    <div class="cta-content">
        <h2>READY TO RIDE?</h2>
        <p>Join thousands of satisfied riders who trust RIDERSHUB for their safety gear</p>
        <div class="cta-buttons">
            <a href="register.php" class="cta-btn primary">
                <i class="fas fa-user-plus"></i> CREATE ACCOUNT
            </a>
            <a href="#" class="cta-btn secondary" onclick="showLoginForm(); return false;">
                <i class="fas fa-sign-in-alt"></i> SIGN IN
            </a>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer">
    <div class="footer-content">
        <div class="footer-grid">
            <div class="footer-col">
                <div class="footer-logo">
                    <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" alt="Ridershub">
                    <span class="footer-brand">RIDERSHUB</span>
                </div>
                <p class="footer-description">
                    Your premier destination for premium motorcycle helmets and gears. Ride safe, ride stylish.
                </p>
               
            </div>

            <div class="footer-col">
                <h3 class="footer-title">QUICK LINKS</h3>
                <ul class="footer-links">
                    <li><a href="#home"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="#" onclick="showLoginForm(); return false;"><i class="fas fa-chevron-right"></i> Login</a></li>
                    <li><a href="register.php"><i class="fas fa-chevron-right"></i> Register</a></li>
                    <li><a href="#"><i class="fas fa-chevron-right"></i> About Us</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h3 class="footer-title">HELP</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-chevron-right"></i> FAQ</a></li>
                    <li><a href="#"><i class="fas fa-chevron-right"></i> Shipping</a></li>
                    <li><a href="#"><i class="fas fa-chevron-right"></i> Returns</a></li>
                    <li><a href="#"><i class="fas fa-chevron-right"></i> Size Guide</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h3 class="footer-title">CONTACT</h3>
                <ul class="footer-contact">
                    <li><i class="fas fa-map-marker-alt"></i> 43 paso de blas Valenzuela city</li>
                    <li><i class="fas fa-phone"></i> +63 956 966 3196</li>
                    <li><i class="fas fa-envelope"></i>kbridershub@gmail.com</li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="copyright">
                &copy; 2025 RIDERSHUB. All rights reserved.
            </div>
            <div class="footer-bottom-links">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
            </div>
        </div>
    </div>
</footer>

<!-- Forgot Password Modal -->
<div id="forgotPasswordModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeForgotPasswordModal()">&times;</span>
        <h2>Reset Password</h2>
        <form id="forgotPasswordForm" method="POST" action="login.php">
            <label><i class="fas fa-envelope"></i> Email Address</label>
            <input type="email" name="email" id="forgotEmail" placeholder="Enter your registered email" required>
            
            <button type="submit" name="forgot_password" class="btn">
                <i class="fas fa-paper-plane"></i> SEND RESET LINK
            </button>
            
            <p style="margin-top: 15px; font-size: 14px; color: #888; text-align: center;">
                We'll send a password reset link to your email.
            </p>
        </form>
    </div>
</div>

<script>
    // Function to show login form
    function showLoginForm() {
        const loginSection = document.getElementById('login');
        loginSection.classList.add('active');
        loginSection.scrollIntoView({ behavior: 'smooth' });
    }

    // Check if there are error messages, if so show the login form automatically
    <?php if (!empty($error_message) || isset($_SESSION['error']) || isset($_GET['sent'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showLoginForm();
    });
    <?php endif; ?>

    // Modal functions
    function openForgotPasswordModal() {
        document.getElementById('forgotPasswordModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    
    function closeForgotPasswordModal() {
        document.getElementById('forgotPasswordModal').style.display = 'none';
        document.body.style.overflow = '';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        var modal = document.getElementById('forgotPasswordModal');
        if (event.target == modal) {
            closeForgotPasswordModal();
        }
    }
    
    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeForgotPasswordModal();
        }
    });
    
    // Handle search functionality
    function handleSearch() {
        const searchTerm = document.getElementById('searchInput').value.trim();
        
        if (searchTerm) {
            Swal.fire({
                title: 'Search Products',
                text: 'Please sign in to search for products.',
                icon: 'info',
                background: '#111',
                color: '#fff',
                confirmButtonColor: '#ff0000',
                showCancelButton: true,
                confirmButtonText: 'SIGN IN',
                cancelButtonText: 'CANCEL'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoginForm();
                }
            });
        } else {
            Swal.fire({
                title: 'Empty Search',
                text: 'Please enter a search term.',
                icon: 'warning',
                background: '#111',
                color: '#fff',
                confirmButtonColor: '#ff0000'
            });
        }
    }

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // Clear error message when user starts typing
    document.querySelectorAll('input[name="email"], input[name="password"]').forEach(input => {
        input.addEventListener('input', function() {
            const errorMsg = document.querySelector('.error-message');
            if (errorMsg) {
                errorMsg.style.display = 'none';
            }
        });
    });

    // Check for login success parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('login') === 'success') {
        Swal.fire({
            title: 'Login Successful!',
            text: 'Welcome back to RIDERSHUB!',
            icon: 'success',
            background: '#111',
            color: '#fff',
            confirmButtonColor: '#ff0000',
            timer: 3000,
            showConfirmButton: false
        });
        
        // Remove the parameter from URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // Check for registration success
    if (urlParams.get('registered') === 'success') {
        Swal.fire({
            title: 'Registration Successful!',
            text: 'Your account has been created. Please login.',
            icon: 'success',
            background: '#111',
            color: '#fff',
            confirmButtonColor: '#ff0000'
        });
        
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    // Add loading animation to login form
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const loginBtn = this.querySelector('.login-btn');
    loginBtn.classList.add('loading');
    loginBtn.disabled = true;
});
// Function to show login form with loading animation
function showLoginForm() {
    const signInBtn = document.querySelector('.sign-in-btn');
    const loginSection = document.getElementById('login');
    
    // Add loading animation to button
    signInBtn.classList.add('loading');
    
    // Simulate loading delay (you can adjust this time)
    setTimeout(() => {
        // Remove loading animation
        signInBtn.classList.remove('loading');
        
        // Show login form with animation
        loginSection.classList.add('active');
        loginSection.scrollIntoView({ behavior: 'smooth' });
    }, 800); // 800ms loading animation
}
</script>

</body>
</html>