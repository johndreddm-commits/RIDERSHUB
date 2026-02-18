<?php
session_start();
require_once "config.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Initialize cart count
$cart_count = 0;
if(isset($_SESSION['cart'])) {
    foreach($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}

// Get real stats from database
$stats_query = "SELECT 
                   COUNT(DISTINCT p.id) as total_products,
                   COUNT(DISTINCT b.id) as total_brands,
                   SUM(CASE WHEN COALESCE(i.quantity, 0) > 0 THEN 1 ELSE 0 END) as in_stock_products
                FROM products p
                LEFT JOIN brands b ON p.brand_id = b.id
                LEFT JOIN inventory i ON p.id = i.product_id
                WHERE p.status = 'active'";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// ✅ FIXED: Get products with stock directly from inventory (no sold)
$products = $conn->query("
    SELECT p.*, 
           IFNULL(b.brand_name, 'unknown') AS brand_name,
           COALESCE(i.quantity, 0) as stock,
           COALESCE(i.min_stock, 5) as min_stock
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN inventory i ON p.id = i.product_id
    WHERE p.status = 'active'
    ORDER BY 
        CASE 
            WHEN COALESCE(i.quantity, 0) > 0 THEN 1
            ELSE 2
        END,
        p.created_at DESC
");

// Fetch brands for navigation
$nav_brands = $conn->query("SELECT * FROM brands ORDER BY brand_name ASC");

// Fetch helmet types for navigation
$helmet_types = ['full-face', 'modular', 'half-face', 'open-face', 'off-road'];

// Get low stock warning for admin
$low_stock_query = "SELECT COUNT(*) as low_stock_count 
                    FROM products p
                    LEFT JOIN inventory i ON p.id = i.product_id
                    WHERE p.status = 'active' 
                    AND COALESCE(i.quantity, 0) <= COALESCE(i.min_stock, 5)
                    AND COALESCE(i.quantity, 0) > 0";
$low_stock_result = $conn->query($low_stock_query);
$low_stock_count = $low_stock_result->fetch_assoc()['low_stock_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Audiowide&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>KB Ridershub | Premium Motorcycle Gear</title>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* ===== RESET & BASE ===== */
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
        
        /* ===== NAVIGATION ===== */
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

        .nav-menu {
            display: flex;
            list-style: none;
            gap: 30px;
        }

        .nav-menu li {
            position: relative;
            animation: fadeInNav 0.5s ease forwards;
            opacity: 0;
        }

        .nav-menu li:nth-child(1) { animation-delay: 0.1s; }
        .nav-menu li:nth-child(2) { animation-delay: 0.15s; }
        .nav-menu li:nth-child(3) { animation-delay: 0.2s; }
        .nav-menu li:nth-child(4) { animation-delay: 0.25s; }
        .nav-menu li:nth-child(5) { animation-delay: 0.3s; }

        @keyframes fadeInNav {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .nav-menu li a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
            padding: 8px 12px;
            display: block;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .nav-menu li a::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 0, 0, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
            z-index: -1;
        }

        .nav-menu li a:hover::before {
            width: 200px;
            height: 200px;
        }

        .nav-menu li a:hover {
            color: red;
        }

        .nav-menu li a i {
            margin-right: 5px;
            transition: transform 0.3s ease;
        }

        .nav-menu li a:hover i {
            transform: rotate(360deg);
        }

        /* Active Navigation Style */
        .nav-menu li.active > a {
            color: red;
            position: relative;
        }

        .nav-menu li.active > a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 12px;
            right: 12px;
            height: 2px;
            background: red;
            border-radius: 2px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                width: 0;
                opacity: 0;
            }
            to {
                width: calc(100% - 24px);
                opacity: 1;
            }
        }

        /* Dropdown Menu */
        .dropdown {
            position: relative;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            background: rgba(0, 0, 0, 0.95);
            border: 1px solid red;
            border-radius: 8px;
            padding: 10px 0;
            min-width: 220px;
            display: none;
            z-index: 1000;
            backdrop-filter: blur(15px);
            box-shadow: 0 10px 30px rgba(255, 0, 0, 0.2);
            animation: dropdownFade 0.3s ease;
        }

        @keyframes dropdownFade {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown:hover .dropdown-menu {
            display: block;
        }

        .dropdown-menu li {
            list-style: none;
            margin: 0;
        }

        .dropdown-menu li a {
            padding: 12px 25px;
            color: #ccc;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }

        .dropdown-menu li a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 100%;
            background: rgba(255, 0, 0, 0.1);
            transition: width 0.3s ease;
            z-index: -1;
        }

        .dropdown-menu li a:hover::before {
            width: 100%;
        }

        .dropdown-menu li a:hover {
            background: rgba(255, 0, 0, 0.15);
            color: red;
            padding-left: 35px;
            transform: translateX(5px);
        }

        .dropdown-menu li a i {
            margin-right: 10px;
            font-size: 0.9rem;
            color: red;
            opacity: 0.7;
            transition: transform 0.3s ease;
        }

        .dropdown-menu li a:hover i {
            transform: scale(1.2);
        }

        /* Cart Count */
        .cart-count {
            background: red;
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 50%;
            min-width: 18px;
            text-align: center;
            font-family: 'Orbitron', sans-serif;
            margin-left: 5px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }

        /* Low Stock Warning */
        .low-stock-warning {
            background: #ff9900;
            color: #000;
            padding: 10px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-left: 15px;
            animation: pulse 2s infinite;
        }

        /* Mobile Navigation */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 10px;
            transition: transform 0.3s ease;
        }
        
        .mobile-menu-btn:hover {
            transform: scale(1.1);
            color: red;
        }
        
        .close-menu-btn {
            display: none;
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            z-index: 1001;
            transition: all 0.3s ease;
        }
        
        .close-menu-btn:hover {
            color: red;
            transform: rotate(90deg);
        }
        
        .mobile-search-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 10px;
            transition: all 0.3s ease;
        }
        
        .mobile-search-btn:hover {
            color: red;
            transform: scale(1.1);
        }
        
        .mobile-search-container {
            display: none;
            position: fixed;
            top: 100px;
            left: 0;
            width: 100%;
            padding: 15px;
            background: rgba(0, 0, 0, 0.95);
            z-index: 999;
            border-bottom: 1px solid red;
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
        
        .mobile-search-input {
            width: 100%;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid red;
            background: #111;
            color: white;
            font-family: 'Orbitron', sans-serif;
            transition: all 0.3s ease;
        }
        
        .mobile-search-input:focus {
            outline: none;
            box-shadow: 0 0 15px rgba(255, 0, 0, 0.3);
            transform: scale(1.02);
        }

        /* ===== HERO SECTION ===== */
        .hero-section {
            margin-top: 100px;
            min-height: 100vh;
            background: linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), 
                        url('https://images.unsplash.com/photo-1558981806-ec527fa84c39?ixlib=rb-1.2.1&auto=format&fit=crop&w=1950&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
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

        .hero-content {
            max-width: 900px;
            padding: 0 20px;
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

        .hero-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 5rem;
            color: white;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 3px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            animation: titleGlow 3s ease-in-out infinite;
        }

        @keyframes titleGlow {
            0%, 100% {
                text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            }
            50% {
                text-shadow: 0 0 30px rgba(255,0,0,0.8), 2px 2px 4px rgba(0,0,0,0.5);
            }
        }

        .hero-subtitle {
            font-size: 1.5rem;
            color: #fff;
            margin-bottom: 30px;
            font-family: 'Orbitron', sans-serif;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
            animation: fadeIn 2s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .hero-button {
            display: inline-block;
            background: red;
            color: white;
            text-decoration: none;
            padding: 15px 40px;
            border-radius: 50px;
            font-weight: bold;
            transition: all 0.3s ease;
            border: 2px solid red;
            font-size: 1.2rem;
            letter-spacing: 2px;
            position: relative;
            overflow: hidden;
        }

        .hero-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .hero-button:hover::before {
            width: 300px;
            height: 300px;
        }

        .hero-button:hover {
            background: transparent;
            color: red;
            transform: translateY(-3px);
        }

        /* Hero Stats */
        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 50px;
            margin-top: 60px;
        }

        .stat-item {
            text-align: center;
            animation: fadeInUp 1s ease forwards;
            animation-delay: 0.5s;
            opacity: 0;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: red;
            font-family: 'Audiowide', sans-serif;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #ccc;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Hero Badges */
        .hero-badges {
            position: absolute;
            bottom: 30px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .hero-badge {
            background: rgba(255, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid red;
            border-radius: 30px;
            padding: 10px 25px;
            color: white;
            font-size: 0.9rem;
            font-weight: 600;
            animation: badgeFloat 2s ease-in-out infinite;
        }

        @keyframes badgeFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .hero-badge i {
            color: red;
            margin-right: 8px;
        }

        /* ===== FEATURED SECTION ===== */
        .featured-section {
            padding: 80px 20px;
            background: linear-gradient(180deg, #0a0a0a 0%, #111 100%);
        }

        .featured-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .featured-card {
            background: #111;
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            border: 1px solid #222;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            min-height: 320px;
            cursor: pointer;
        }

        .featured-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,0,0,0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .featured-card:hover::before {
            opacity: 1;
        }

        .featured-card:hover {
            transform: translateY(-10px);
            border-color: red;
            box-shadow: 0 10px 30px rgba(255, 0, 0, 0.2);
        }

        .featured-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 0, 0, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.5rem;
            color: red;
            transition: all 0.3s ease;
        }

        .featured-card:hover .featured-icon {
            transform: rotate(360deg);
            background: red;
            color: white;
        }

        .featured-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.3rem;
            color: red;
            margin-bottom: 15px;
            min-height: 40px;
        }

        .featured-text {
            color: #888;
            line-height: 1.5;
            font-size: 0.9rem;
            margin: 0;
            flex: 1;
            display: flex;
            align-items: center;
        }

        /* ===== BRANDS SECTION ===== */
        .brands-section {
            padding: 60px 20px;
            background: #0a0a0a;
            text-align: center;
        }

        .section-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 2.5rem;
            color: red;
            margin-bottom: 20px;
            text-align: center;
        }

        .section-subtitle {
            color: #888;
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto 50px;
        }

        .brands-slider {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 30px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .brand-item {
            background: #111;
            border: 1px solid #222;
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .brand-item:hover {
            transform: scale(1.1);
            border-color: red;
            box-shadow: 0 5px 20px rgba(255, 0, 0, 0.3);
        }

        .brand-item i {
            font-size: 3rem;
            color: red;
            margin-bottom: 10px;
        }

        .brand-item span {
            display: block;
            font-weight: 600;
            color: white;
        }

        /* ===== ABOUT SECTION ===== */
        .about-section {
            padding: 100px 20px;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a0000 100%);
            position: relative;
            overflow: hidden;
        }

        .about-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,0,0,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .about-container {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .about-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin: 50px 0;
        }

        .about-card {
            background: rgba(20, 20, 20, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 0, 0, 0.3);
            border-radius: 30px;
            padding: 40px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .about-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, transparent, rgba(255, 0, 0, 0.1), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }

        .about-card:hover::before {
            transform: translateX(100%);
        }

        .about-card:hover {
            transform: translateY(-10px);
            border-color: red;
            box-shadow: 0 20px 40px rgba(255, 0, 0, 0.2);
        }

        .mission-card { border-top: 4px solid #ff4444; }
        .vision-card { border-top: 4px solid #ffaa00; }
        .values-card { border-top: 4px solid #00ff88; }
        .why-card { border-top: 4px solid #00aaff; }

        .about-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 0, 0, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            font-size: 2.5rem;
            color: red;
            transition: all 0.3s ease;
        }

        .about-card:hover .about-icon {
            transform: scale(1.1) rotate(360deg);
            background: red;
            color: white;
        }

        .about-card h3 {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.8rem;
            color: red;
            margin-bottom: 20px;
        }

        .about-card p {
            color: #ccc;
            line-height: 1.8;
            font-size: 1.1rem;
            margin-bottom: 25px;
        }

        .mission-badge, .vision-badge {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .mission-badge span, .vision-badge span {
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid red;
            border-radius: 30px;
            padding: 5px 15px;
            font-size: 0.9rem;
            color: red;
            transition: all 0.3s ease;
        }

        .mission-badge span:hover, .vision-badge span:hover {
            background: red;
            color: white;
            transform: scale(1.05);
        }

        .values-list, .why-list {
            list-style: none;
            padding: 0;
        }

        .values-list li, .why-list li {
            color: #ccc;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .values-list li:hover, .why-list li:hover {
            transform: translateX(10px);
            color: white;
        }

        .values-list li i, .why-list li i {
            color: red;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .values-list li:hover i, .why-list li:hover i {
            transform: scale(1.2);
        }

        .values-list li strong {
            color: red;
        }

        /* About Stats */
        .about-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            margin-top: 60px;
            padding: 40px;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50px;
            border: 1px solid rgba(255, 0, 0, 0.2);
        }

        .stat-box {
            text-align: center;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            transform: scale(1.1);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            color: red;
            font-family: 'Audiowide', sans-serif;
            margin-bottom: 10px;
        }

        .stat-label {
            color: #888;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ===== CTA SECTION ===== */
        .cta-section {
            padding: 100px 20px;
            background: linear-gradient(rgba(0,0,0,0.8), rgba(0,0,0,0.8)), 
                        url('https://images.unsplash.com/photo-1519751138087-5bf79df55d4b?ixlib=rb-1.2.1&auto=format&fit=crop&w=1950&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            text-align: center;
        }

        .cta-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 3rem;
            color: white;
            margin-bottom: 20px;
        }

        .cta-text {
            color: #ccc;
            font-size: 1.2rem;
            max-width: 600px;
            margin: 0 auto 30px;
        }

        .cta-button {
            display: inline-block;
            background: red;
            color: white;
            text-decoration: none;
            padding: 15px 40px;
            border-radius: 50px;
            font-weight: bold;
            transition: all 0.3s ease;
            border: 2px solid red;
            font-size: 1.2rem;
            letter-spacing: 2px;
            margin: 0 10px;
        }

        .cta-button:hover {
            background: transparent;
            color: red;
            transform: translateY(-3px);
        }

        .cta-button.outline {
            background: transparent;
            border-color: white;
            color: white;
        }

        .cta-button.outline:hover {
            border-color: red;
            color: red;
        }

        /* ===== PRODUCTS SECTION ===== */
        .products-section {
            padding: 60px 20px;
            background: #0a0a0a;
            scroll-margin-top: 100px;
            display: none;
            animation: fadeInProducts 1s ease;
        }

        @keyframes fadeInProducts {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .products-section.visible {
            display: block;
        }
        
        .products-section h2 {
            text-align: center;
            font-family: 'Audiowide', sans-serif;
            font-size: 2.5rem;
            color: red;
            margin-bottom: 40px;
        }
        
        .products-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
            gap: 30px; 
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .product-card { 
            display: flex; 
            flex-direction: column; 
            background: #111;
            border-radius: 20px; 
            padding: 25px; 
            height: 100%; 
            border: 1px solid #222; 
            transition: all 0.3s ease;
            position: relative;
            animation: cardFadeIn 0.5s ease forwards;
            animation-delay: calc(0.1s * var(--i));
            opacity: 0;
        }

        .product-card:nth-child(1) { --i: 1; }
        .product-card:nth-child(2) { --i: 2; }
        .product-card:nth-child(3) { --i: 3; }
        .product-card:nth-child(4) { --i: 4; }
        .product-card:nth-child(5) { --i: 5; }
        .product-card:nth-child(6) { --i: 6; }

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
        
        .product-card:hover { 
            border-color: red; 
            box-shadow: 0 10px 30px rgba(255, 0, 0, 0.2);
            transform: translateY(-5px);
        }
        
        .product-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: red;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            z-index: 2;
            animation: badgePulse 2s infinite;
        }

        .product-badge.low-stock {
            background: #ff9900;
            color: #000;
        }

        .product-badge.out-of-stock {
            background: #666;
        }

        @keyframes badgePulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .product-card img { 
            width: 100%; 
            height: 250px; 
            object-fit: contain; 
            background: #fff; 
            border-radius: 15px; 
            padding: 15px; 
            margin-bottom: 15px;
            transition: transform 0.3s ease;
        }
        
        .product-card:hover img {
            transform: scale(1.08);
        }
        
        .product-info { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            justify-content: center; 
            text-align: center; 
            padding: 15px 0;
        }
        
        .product-name { 
            font-family: 'Audiowide', sans-serif; 
            margin-bottom: 10px; 
            min-height: 70px; 
            display: -webkit-box; 
            -webkit-line-clamp: 2; 
            -webkit-box-orient: vertical; 
            overflow: hidden;
            font-size: 1.3rem;
            color: red;
            transition: color 0.3s ease;
        }

        .product-card:hover .product-name {
            color: #ff4444;
        }
        
        .product-brand {
            color: #888;
            font-size: 0.9rem;
            margin-bottom: 10px;
            transition: color 0.3s ease;
        }

        .product-card:hover .product-brand {
            color: #aaa;
        }
        
        .product-price { 
            color: red; 
            font-size: 2rem; 
            font-weight: 800; 
            margin-bottom: 15px;
            transition: transform 0.3s ease; 
        }

        .product-card:hover .product-price {
            transform: scale(1.05);
        }
        
        /* Stock display styles */
        .stock-tag {
            background: rgba(0, 255, 0, 0.1);
            color: #00ff00;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.9rem;
            display: inline-block;
            margin: 10px auto;
            border: 1px solid #00ff0033;
            transition: all 0.3s ease;
            text-align: center;
        }

        .product-card:hover .stock-tag {
            background: rgba(0, 255, 0, 0.2);
            transform: scale(1.05);
        }
        
        .stock-tag.low-stock {
            background: rgba(255, 165, 0, 0.1);
            color: #ff9900;
            border-color: #ff990033;
        }
        
        .out-of-stock-tag {
            background: rgba(255, 0, 0, 0.1);
            color: #ff4444;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.9rem;
            display: inline-block;
            margin: 10px auto;
            border: 1px solid rgba(255, 0, 0, 0.3);
            text-align: center;
            transition: all 0.3s ease;
        }

        .product-card:hover .out-of-stock-tag {
            background: rgba(255, 0, 0, 0.2);
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: auto;
            width: 100%;
        }
        
        .cart-btn,
        .reserve-btn {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
        }

        .cart-btn::before,
        .reserve-btn::before {
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

        .cart-btn:hover::before,
        .reserve-btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .cart-btn { 
            background: #333; 
            color: white;
            border: 1px solid #ff4444;
        }
        
        .cart-btn:hover:not(:disabled) {
            background: #ff4444;
            transform: translateY(-2px);
        }
        
        .reserve-btn { 
            background: red; 
            color: white;
        }
        
        .reserve-btn:hover:not(:disabled) {
            background: #cc0000;
            transform: translateY(-2px);
        }
        
        .cart-btn:disabled,
        .reserve-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #444;
            border-color: #666;
        }

        /* ===== MODALS ===== */
        .quick-cart-modal,
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 9999; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.9); 
            backdrop-filter: blur(10px); 
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        .quick-cart-content,
        .modal-content { 
            background: #111; 
            margin: 5% auto; 
            padding: 35px; 
            border: 2px solid red; 
            width: 90%; 
            max-width: 450px; 
            max-height: 90vh; 
            overflow-y: auto; 
            border-radius: 20px; 
            color: white; 
            font-family: 'Orbitron', sans-serif; 
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
        
        .quick-cart-content h2,
        .modal-content h2 {
            font-family: 'Audiowide', sans-serif;
            margin-bottom: 25px;
            color: red;
            font-size: 1.8rem;
            text-align: center;
        }
        
        .close-modal {
            color: #666;
            position: absolute;
            right: 25px;
            top: 15px;
            font-size: 35px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            line-height: 1;
        }
        
        .close-modal:hover {
            color: red;
            transform: rotate(90deg);
        }
        
        #quickCartForm label,
        #reservationForm label { 
            display: block; 
            margin-top: 20px; 
            font-size: 0.9rem; 
            color: #ccc; 
        }
        
        #quickCartForm select,
        #quickCartForm input,
        #reservationForm input, 
        #reservationForm select { 
            width: 100%; 
            padding: 12px 15px; 
            margin-top: 5px; 
            background: #1a1a1a; 
            border: 1px solid #333; 
            color: white; 
            border-radius: 8px; 
            box-sizing: border-box; 
            font-family: 'Orbitron', sans-serif;
            transition: all 0.3s ease;
        }
        
        #quickCartForm select:focus,
        #quickCartForm input:focus,
        #reservationForm input:focus, 
        #reservationForm select:focus { 
            border-color: red; 
            outline: none;
            transform: scale(1.02);
        }
        
        .add-to-cart-submit,
        .submit-res-btn { 
            width: 100%; 
            background: red; 
            color: white; 
            padding: 15px; 
            border: none; 
            font-weight: bold; 
            cursor: pointer; 
            border-radius: 8px; 
            margin-top: 30px; 
            font-family: 'Orbitron', sans-serif;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
        }
        
        .add-to-cart-submit:hover,
        .submit-res-btn:hover {
            background: #cc0000;
            transform: translateY(-2px);
        }
        
        .selection-row { 
            display: flex; 
            gap: 20px;
            margin-top: 10px;
        }
        
        .selection-row div { 
            flex: 1;
        }

        .stock-info-modal {
            display: block;
            color: #00ff00;
            margin-top: 5px;
            font-size: 0.9rem;
        }

        /* ===== FILTER BUTTONS ===== */
        .filter-buttons {
            display: none;
            justify-content: center;
            gap: 15px;
            margin: 30px auto;
            padding: 0 20px;
            flex-wrap: wrap;
            max-width: 1200px;
        }
        
        .filter-btn {
            padding: 10px 25px;
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid red;
            color: white;
            border-radius: 30px;
            cursor: pointer;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
        }
        
        .filter-btn:hover,
        .filter-btn.active {
            background: red;
            transform: translateY(-2px);
        }

        /* ===== LOADING OVERLAY ===== */
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 20px;
        }
        
        .loading.active {
            display: flex;
        }
        
        .loading p {
            color: red;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #333;
            border-top: 4px solid red;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ===== BACK TO TOP BUTTON ===== */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 55px;
            height: 55px;
            background: red;
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            justify-content: center;
            align-items: center;
            font-size: 22px;
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
        }

        /* ===== FOOTER SECTION ===== */
        .footer {
            background: #111;
            border-top: 2px solid red;
            padding: 60px 20px 20px;
            margin-top: 60px;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 50px;
        }

        .footer-col {
            display: flex;
            flex-direction: column;
            gap: 20px;
            animation: slideUp 0.5s ease forwards;
            animation-delay: calc(0.1s * var(--i));
            opacity: 0;
        }

        .footer-col:nth-child(1) { --i: 1; }
        .footer-col:nth-child(2) { --i: 2; }
        .footer-col:nth-child(3) { --i: 3; }
        .footer-col:nth-child(4) { --i: 4; }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.3s ease;
        }

        .footer-logo:hover {
            transform: scale(1.05);
        }

        .footer-logo img {
            width: 50px;
            height: 50px;
            filter: drop-shadow(0 0 10px rgba(255, 0, 0, 0.5));
            transition: all 0.3s ease;
        }

        .footer-logo:hover img {
            filter: drop-shadow(0 0 20px rgba(255, 0, 0, 0.8));
        }

        .footer-brand {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.5rem;
            background: linear-gradient(90deg, #fff, #ff0000);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .footer-description {
            color: #888;
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 10px;
            transition: color 0.3s ease;
        }

        .footer-description:hover {
            color: #aaa;
        }

        .social-links {
            display: flex;
            gap: 15px;
        }

        .social-link {
            width: 40px;
            height: 40px;
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid rgba(255, 0, 0, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
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
            background: red;
            transform: translateY(-5px) scale(1.1);
            border-color: red;
        }

        .footer-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.2rem;
            color: red;
            margin-bottom: 10px;
            position: relative;
            padding-bottom: 10px;
        }

        .footer-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 2px;
            background: red;
            transition: width 0.3s ease;
        }

        .footer-col:hover .footer-title::after {
            width: 80px;
        }

        .footer-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .footer-links li a {
            color: #888;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-links li a i {
            font-size: 0.8rem;
            color: red;
            transition: transform 0.3s ease;
        }

        .footer-links li a:hover {
            color: red;
            transform: translateX(5px);
        }

        .footer-links li a:hover i {
            transform: rotate(360deg);
        }

        .footer-contact {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .footer-contact li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #888;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .footer-contact li:hover {
            transform: translateX(5px);
        }

        .footer-contact li i {
            color: red;
            font-size: 1.1rem;
            margin-top: 3px;
            transition: transform 0.3s ease;
        }

        .footer-contact li:hover i {
            transform: scale(1.2);
        }

        .footer-contact li span {
            flex: 1;
            line-height: 1.5;
        }

        /* Newsletter */
        .newsletter {
            text-align: center;
            padding: 40px 0;
            border-top: 1px solid #222;
            border-bottom: 1px solid #222;
            margin-bottom: 30px;
        }

        .newsletter-title {
            font-family: 'Audiowide', sans-serif;
            font-size: 1.5rem;
            color: red;
            margin-bottom: 10px;
        }

        .newsletter-text {
            color: #888;
            margin-bottom: 20px;
        }

        .newsletter-form {
            display: flex;
            max-width: 500px;
            margin: 0 auto;
            gap: 10px;
        }

        .newsletter-form input {
            flex: 1;
            padding: 15px 20px;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            color: white;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .newsletter-form input:focus {
            outline: none;
            border-color: red;
        }

        .newsletter-form button {
            padding: 15px 30px;
            background: red;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            font-family: 'Orbitron', sans-serif;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .newsletter-form button:hover {
            background: #cc0000;
            transform: translateY(-2px);
        }

        /* Bottom Bar */
        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            padding-top: 20px;
        }

        .copyright {
            color: #666;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .copyright:hover {
            color: #888;
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
            position: relative;
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

        .footer-bottom-links a:hover {
            color: red;
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 992px) {
            .nav {
                padding: 15px 30px;
            }
            
            .nav-logo {
                width: 55px;
                height: 55px;
            }
            
            .nav-title {
                font-size: 24px;
            }
            
            .hero-title {
                font-size: 3.5rem;
            }
            
            .hero-subtitle {
                font-size: 1.3rem;
            }
            
            .hero-stats {
                gap: 30px;
            }
            
            .stat-number {
                font-size: 2rem;
            }
            
            .featured-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .about-grid {
                grid-template-columns: 1fr;
            }
            
            .about-stats {
                grid-template-columns: repeat(2, 1fr);
                padding: 30px;
            }
        }
        
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
            
            .mobile-menu-btn,
            .mobile-search-btn {
                display: block;
            }
            
            .nav-menu {
                position: fixed;
                top: 0;
                right: -100%;
                width: 300px;
                height: 100vh;
                background: rgba(0, 0, 0, 0.98);
                flex-direction: column;
                padding: 80px 25px 30px;
                transition: right 0.3s ease;
                z-index: 1000;
                border-left: 2px solid red;
                overflow-y: auto;
            }
            
            .nav-menu.active {
                right: 0;
            }
            
            .close-menu-btn {
                display: block;
            }
            
            .filter-buttons {
                display: flex;
            }
            
            .hero-section {
                margin-top: 85px;
                min-height: 90vh;
            }
            
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
            
            .hero-button {
                padding: 12px 30px;
                font-size: 1rem;
            }
            
            .hero-stats {
                flex-direction: column;
                gap: 20px;
            }
            
            .featured-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .about-grid {
                grid-template-columns: 1fr;
            }
            
            .about-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .products-section h2 {
                font-size: 2rem;
            }
            
            .selection-row {
                flex-direction: column;
            }
            
            .cta-title {
                font-size: 2rem;
            }
            
            .cta-buttons {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .cta-button {
                margin: 5px 0;
            }
            
            .newsletter-form {
                flex-direction: column;
            }

            .newsletter-form button {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .hero-title {
                font-size: 2rem;
            }
            
            .hero-subtitle {
                font-size: 0.9rem;
            }
            
            .featured-grid {
                grid-template-columns: 1fr;
                max-width: 350px;
                margin: 0 auto;
            }
            
            .products-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .cart-btn,
            .reserve-btn {
                width: 100%;
            }
            
            .brands-slider {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .about-stats {
                grid-template-columns: 1fr;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .footer-bottom {
                flex-direction: column;
                text-align: center;
            }

            .footer-bottom-links {
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading" id="loadingOverlay">
        <div class="spinner"></div>
        <p>LOADING...</p>
    </div>

    <!-- Back to Top Button -->
    <button class="back-to-top" id="backToTop">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-left">
            <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" class="nav-logo" alt="Logo">
            <span class="nav-title">RIDERSHUB</span>
        </div>
        
        <!-- Mobile Menu Button -->
        <div style="display: flex; gap: 10px; align-items: center;">
            <?php if($low_stock_count > 0 && isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
            <a href="inventory.php" class="low-stock-warning">
                <i class="fas fa-exclamation-triangle"></i> <?= $low_stock_count ?> Low Stock
            </a>
            <?php endif; ?>
            <button class="mobile-search-btn" id="mobileSearchBtn">
                <i class="fas fa-search"></i>
            </button>
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <ul class="nav-menu" id="navMenu">
            <button class="close-menu-btn" id="closeMenuBtn">
                <i class="fas fa-times"></i>
            </button>
            
            <li class="<?= (basename($_SERVER['PHP_SELF']) == 'index.php' && !isset($_GET['brand']) && !isset($_GET['type'])) ? 'active' : '' ?>">
                <a href="index.php"><i class="fas fa-home"></i> HOME</a>
            </li>
            
            <li class="dropdown <?= (isset($_GET['brand']) || isset($_GET['type'])) ? 'active' : '' ?>">
                <a href="#" class="dropdown-toggle"><i class="fas fa-helmet-safety"></i> PRODUCTS ▾</a>
                <ul class="dropdown-menu">
                    <li><a href="javascript:void(0)" onclick="showProducts()"><i class="fas fa-list"></i> ALL PRODUCTS</a></li>
                    <?php
                    $nav_brands = $conn->query("SELECT * FROM brands ORDER BY brand_name ASC");
                    if($nav_brands && $nav_brands->num_rows > 0):
                        while($row = $nav_brands->fetch_assoc()): 
                            $b_name = $row['brand_name'];
                            $b_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $b_name));
                    ?>
                        <li><a href="javascript:void(0)" onclick="filterByBrandAndShow('<?= $b_slug ?>')"><i class="fas fa-tag"></i> <?= strtoupper($b_name) ?></a></li>
                    <?php 
                        endwhile;
                    endif; 
                    ?>
                </ul>
            </li>
            
            <li class="dropdown">
                <a href="#" class="dropdown-toggle"><i class="fas fa-tag"></i> HELMET TYPE ▾</a>
                <ul class="dropdown-menu">
                    <li><a href="javascript:void(0)" onclick="showProducts()"><i class="fas fa-list"></i> ALL TYPES</a></li>
                    <?php foreach($helmet_types as $type): ?>
                    <li><a href="javascript:void(0)" onclick="filterByTypeAndShow('<?= $type ?>')">
                        <i class="fas fa-helmet-safety"></i> <?= strtoupper(str_replace('-', ' ', $type)) ?>
                    </a></li>
                    <?php endforeach; ?>
                </ul>
            </li>
            
            <li>
                <a href="cart.php"><i class="fas fa-shopping-cart"></i> CART <span class="cart-count" id="navCartCount"><?= $cart_count ?></span></a>
            </li>
            
            <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle"><i class="fas fa-cog"></i> ADMIN ▾</a>
                <ul class="dropdown-menu">
                    <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
                    <li><a href="products.php"><i class="fas fa-box"></i> Products</a></li>
                    <li><a href="inventory.php"><i class="fas fa-clipboard-list"></i> Inventory</a></li>
                    <li><a href="reservations.php"><i class="fas fa-calendar-check"></i> Reservations</a></li>
                </ul>
            </li>
            <?php endif; ?>
            
            <li>
                <a href="logout.php" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
            </li>
        </ul>
    </nav>

    <!-- Mobile Search Container -->
    <div class="mobile-search-container" id="mobileSearchContainer">
        <input type="text" class="mobile-search-input" placeholder="Search products..." id="mobileSearchInput">
    </div>

    <!-- Filter Buttons -->
    <div class="filter-buttons" id="filterButtons">
        <button class="filter-btn active" data-filter="all">ALL</button>
        <button class="filter-btn" data-filter="brand">BRANDS</button>
        <button class="filter-btn" data-filter="type">TYPES</button>
    </div>

    <!-- Hero Section -->
    <section class="hero-section" id="hero">
        <div class="hero-content">
            <h1 class="hero-title">KNOW YOUR BREAKS</h1>
            <p class="hero-subtitle">When necessities meets your expectation</p>
            <a href="javascript:void(0)" onclick="showProducts()" class="hero-button">EXPLORE COLLECTION</a>
            
            <!-- Hero Stats -->
            <div class="hero-stats">
                <div class="stat-item">
                    <div class="stat-number"><?= $stats['total_products'] ?? 0 ?></div>
                    <div class="stat-label">Products</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $stats['total_brands'] ?? 0 ?></div>
                    <div class="stat-label">Brands</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $stats['in_stock_products'] ?? 0 ?></div>
                    <div class="stat-label">In Stock</div>
                </div>
            </div>
        </div>
        
        <!-- Hero Badges -->
        <div class="hero-badges">
            <span class="hero-badge"><i class="fas fa-shield-alt"></i> BIR Certified</span>
            <span class="hero-badge"><i class="fas fa-ban"></i> No Refund</span>
            <span class="hero-badge"><i class="fas fa-undo-alt"></i> No Return</span>
            <span class="hero-badge"><i class="fas fa-exchange-alt"></i> Yes to Exchange</span>
        </div>
    </section>

    <!-- Featured Categories Section -->
    <section class="featured-section" id="featured">
        <h2 class="section-title">FEATURED CATEGORIES</h2>
        <p class="section-subtitle">Discover our most popular helmet types, trusted by riders worldwide</p>
        
        <div class="featured-grid">
            <?php foreach($helmet_types as $index => $type): 
                $icons = ['full-face' => 'fa-helmet-safety', 
                         'modular' => 'fa-motorcycle',
                         'half-face' => 'fa-helmet-battle',
                         'open-face' => 'fa-wind',
                         'off-road' => 'fa-mountain'];
                $icon = $icons[$type] ?? 'fa-helmet-safety';
                $titles = ['full-face' => 'Full Face',
                          'modular' => 'Modular',
                          'half-face' => 'Half Face',
                          'open-face' => 'Open Face',
                          'off-road' => 'Off Road'];
                $title = $titles[$type] ?? ucfirst(str_replace('-', ' ', $type));
            ?>
            <div class="featured-card" onclick="filterByTypeAndShow('<?= $type ?>')">
                <div class="featured-icon">
                    <i class="fas <?= $icon ?>"></i>
                </div>
                <h3 class="featured-title"><?= $title ?></h3>
                <p class="featured-text">
                    <?php 
                    switch($type) {
                        case 'full-face':
                            echo 'Maximum protection for high-speed riding with complete head coverage.';
                            break;
                        case 'modular':
                            echo 'Versatile flip-up design combining full-face protection with open-face convenience.';
                            break;
                        case 'half-face':
                            echo 'Classic style with enhanced visibility and airflow for urban cruising.';
                            break;
                        case 'open-face':
                            echo 'Traditional design offering excellent visibility and airflow for casual riding.';
                            break;
                        case 'off-road':
                            echo 'Dual-sport and motocross helmets designed for adventure and extreme terrain.';
                            break;
                        default:
                            echo 'Premium quality helmet for your riding needs.';
                    }
                    ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Brands Section -->
    <section class="brands-section" id="brands">
        <h2 class="section-title">TOP BRANDS</h2>
        <p class="section-subtitle">Ride with confidence with the world's most trusted helmet manufacturers</p>
        
        <div class="brands-slider">
            <?php
            $brands_display = $conn->query("SELECT * FROM brands ORDER BY brand_name ASC LIMIT 6");
            if($brands_display && $brands_display->num_rows > 0):
                while($brand = $brands_display->fetch_assoc()):
            ?>
                <div class="brand-item" onclick="filterByBrandAndShow('<?= strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $brand['brand_name'])) ?>')">
                    <i class="fas fa-tag"></i>
                    <span><?= strtoupper($brand['brand_name']) ?></span>
                </div>
            <?php 
                endwhile;
            endif; 
            ?>
        </div>
    </section>

    <!-- About Section -->
    <section class="about-section" id="about">
        <div class="about-container">
            <h2 class="section-title">ABOUT RIDERSHUB</h2>
            <p class="section-subtitle">Your trusted partner on every ride</p>
            
            <div class="about-content">
                <div class="about-grid">
                    <!-- Company Story -->
                    <div class="about-card mission-card">
                        <div class="about-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <h3>Our Mission</h3>
                        <p>To deliver stylish, high-quality, and safety-certified helmets at prices everyone can afford—because every ride deserves protection without compromise.</p>
                        <div class="mission-badge">
                            <span>Safety First</span>
                            <span>Affordable</span>
                            <span>Quality Assured</span>
                        </div>
                    </div>
                    
                    <div class="about-card vision-card">
                        <div class="about-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <h3>Our Vision</h3>
                        <p>To become a trusted and leading helmet store known for delivering superior protection, excellent customer service, and affordable pricing—ensuring that every rider can prioritize safety without compromising quality or cost.</p>
                        <div class="vision-badge">
                            <span>Trusted</span>
                            <span>Leading</span>
                            <span>Customer First</span>
                        </div>
                    </div>
                    
                    <!-- Core Values -->
                    <div class="about-card values-card">
                        <div class="about-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <h3>Our Core Values</h3>
                        <ul class="values-list">
                            <li><i class="fas fa-shield-alt"></i> <strong>Safety Above All</strong> - BIR certified helmets only</li>
                            <li><i class="fas fa-hand-holding-heart"></i> <strong>Customer Focus</strong> - Your satisfaction is our priority</li>
                            <li><i class="fas fa-star"></i> <strong>Quality First</strong> - Premium products, affordable prices</li>
                            <li><i class="fas fa-handshake"></i> <strong>Integrity</strong> - Honest and transparent transactions</li>
                        </ul>
                    </div>
                    
                    <!-- Why Choose Us -->
                    <div class="about-card why-card">
                        <div class="about-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Why Choose Us?</h3>
                        <ul class="why-list">
                            <li><i class="fas fa-check"></i> BIR Certified Helmets</li>
                            <li><i class="fas fa-check"></i> Easy Reservation System</li>
                            <li><i class="fas fa-check"></i> Affordable Price Guarantee</li>
                            <li><i class="fas fa-check"></i> Friendly and Knowledgeable Staff</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Stats Counter -->
            <div class="about-stats">
                <div class="stat-box">
                    <div class="stat-number" data-target="1000">0+</div>
                    <div class="stat-label">Happy Riders</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number" data-target="50">0+</div>
                    <div class="stat-label">Helmet Models</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number" data-target="5">0+</div>
                    <div class="stat-label">Years of Service</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number" data-target="24">0/7</div>
                    <div class="stat-label">Customer Support</div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section" id="cta">
        <h2 class="cta-title">READY TO RIDE?</h2>
        <p class="cta-text">Join thousands of satisfied riders who trust Ridershub for their safety gear. Explore our collection now!</p>
        <div class="cta-buttons">
            <a href="javascript:void(0)" onclick="showProducts()" class="cta-button">SHOP NOW</a>
            <a href="#contact" class="cta-button outline">CONTACT US</a>
        </div>
    </section>

    <!-- Products Section -->
    <section class="products-section" id="products">
        <h2>FEATURED HELMETS</h2>
        <div class="products-grid">
            <?php 
            if ($products && $products->num_rows > 0):
                while($p = $products->fetch_assoc()): 
                    $helmet_type = strtolower($p['helmet_type'] ?? 'full-face');
                    $helmet_type_for_filter = str_replace(' ', '-', $helmet_type);
                    
                    // Get stock directly from inventory
                    $stock = $p['stock'] ?? 0;
                    $min_stock = $p['min_stock'] ?? 5;
                    
                    $brand_name = strtolower($p['brand_name'] ?? 'unknown');
                    
                    // Determine stock status for badge
                    $badge_class = '';
                    $badge_text = '';
                    
                    if($stock > 0) {
                        if($stock <= $min_stock) {
                            $badge_class = 'low-stock';
                            $badge_text = 'LOW STOCK';
                        } else {
                            $badge_class = 'in-stock';
                            $badge_text = 'IN STOCK';
                        }
                    } else {
                        $badge_class = 'out-of-stock';
                        $badge_text = 'OUT OF STOCK';
                    }
            ?>
                <div class="product-card" 
                     data-brand="<?= $brand_name ?>" 
                     data-helmet-type="<?= $helmet_type_for_filter ?>"
                     data-name="<?= strtolower(htmlspecialchars($p['name'] ?? '')) ?>"
                     data-stock="<?= $stock ?>">
                    
                    <!-- Product Badge -->
                    <span class="product-badge <?= $badge_class ?>">
                        <i class="fas fa-<?= $stock > 0 ? ($stock <= $min_stock ? 'exclamation-triangle' : 'check-circle') : 'times-circle' ?>"></i> 
                        <?= $badge_text ?>
                    </span>
                    
                    <img src="uploads/<?= htmlspecialchars($p['image'] ?? '') ?>" alt="<?= htmlspecialchars($p['name'] ?? 'Product') ?>">
                    
                    <div class="product-info">
                        <h3 class="product-name"><?= htmlspecialchars($p['name'] ?? 'Unnamed Product') ?></h3>
                        <div class="product-brand"><i class="fas fa-tag"></i> <?= ucfirst($p['brand_name'] ?? 'Unknown') ?></div>
                        <p class="product-price">₱<?= number_format($p['price'] ?? 0, 2) ?></p>
                        
                        <!-- Stock display - shows current stock only -->
                        <?php if($stock > 0): ?>
                            <span class="stock-tag <?= $stock <= $min_stock ? 'low-stock' : '' ?>">
                                <i class="fas fa-check-circle"></i> <?= $stock ?> Available
                            </span>
                        <?php else: ?>
                            <span class="out-of-stock-tag">
                                <i class="fas fa-times-circle"></i> Out of Stock
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="cart-btn" 
                                onclick="addToCart(<?= $p['id'] ?? 0 ?>, '<?= htmlspecialchars(addslashes($p['name'] ?? '')) ?>', <?= $p['price'] ?? 0 ?>, '<?= htmlspecialchars(addslashes($p['colors'] ?? 'Standard')) ?>', '<?= htmlspecialchars(addslashes($p['sizes'] ?? 'One Size')) ?>', <?= $stock ?>)"
                                <?= $stock > 0 ? '' : 'disabled' ?>>
                            <i class="fas fa-cart-plus"></i> CART
                        </button>
                        <button class="reserve-btn" 
                                data-name="<?= htmlspecialchars($p['name'] ?? '') ?>" 
                                data-id="<?= $p['id'] ?? 0 ?>"
                                data-colors="<?= htmlspecialchars($p['colors'] ?? 'Standard') ?>"
                                data-sizes="<?= htmlspecialchars($p['sizes'] ?? 'One Size') ?>"
                                data-stock="<?= $stock ?>"
                                <?= $stock > 0 ? '' : 'disabled' ?>> 
                            <i class="fas fa-calendar-check"></i> RESERVE
                        </button>
                    </div>
                </div>
            <?php 
                endwhile;
            else: 
            ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px;">
                    <i class="fas fa-box-open" style="font-size: 4rem; color: #333;"></i>
                    <h3>No products available</h3>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Quick Add to Cart Modal -->
    <div id="quickCartModal" class="quick-cart-modal">
        <div class="quick-cart-content">
            <span class="close-modal" onclick="closeModal('quickCartModal')">&times;</span>
            <h2>ADD TO CART</h2>
            <p id="quickCartProductName" style="color: red;"></p>
            <p id="quickCartPrice" style="color: white; font-size: 1.4rem;"></p>
            
            <form id="quickCartForm">
                <input type="hidden" name="product_id" id="quickCartProductId">
                <input type="hidden" name="action" value="add_to_cart">
                
                <label>COLOR</label>
                <select name="color" id="quickCartColor" required></select>
                
                <label>SIZE</label>
                <select name="size" id="quickCartSize" required></select>
                
                <label>QUANTITY</label>
                <input type="number" name="quantity" id="quickCartQuantity" min="1" value="1" required>
                
                <button type="submit" class="add-to-cart-submit">
                    <i class="fas fa-cart-plus"></i> ADD TO CART
                </button>
            </form>
        </div>
    </div>

    <!-- Reservation Modal -->
    <div id="reserveModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('reserveModal')">&times;</span>
            <h2>RESERVATION</h2>
            <p id="modalProductName" style="color: red;"></p>
            <div id="modalStockDisplay" class="stock-tag">Current Stock: 0</div> 
            
            <form id="reservationForm">
                <input type="hidden" name="product_id" id="modalProductId">
                <input type="hidden" name="action" value="reserve_from_cart">
                
                <label>FULL NAME</label>
                <input type="text" name="customer_name" id="customerName" required placeholder="Juan Dela Cruz" value="<?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>">
                
                <label>CONTACT NUMBER</label>
                <input type="tel" name="phone" id="customerPhone" required placeholder="09123456789" value="<?= htmlspecialchars($_SESSION['phone'] ?? '') ?>">

                <div class="selection-row">
                    <div>
                        <label>COLOR</label>
                        <select name="selected_color" id="modalColor" required></select>
                    </div>
                    <div>
                        <label>SIZE</label>
                        <select name="selected_size" id="modalSize" required></select>
                    </div>
                </div>

                <label>QUANTITY</label>
                <input type="number" name="quantity" id="modalQuantity" min="1" value="1" required>

                <label>PICKUP DATE</label>
                <input type="date" name="pickup_date" id="pickupDate" required>
                
                <button type="submit" class="submit-res-btn">CONFIRM RESERVATION</button>
            </form>
        </div>
    </div>

    <!-- Footer Section -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-grid">
                <!-- Company Info -->
                <div class="footer-col">
                    <div class="footer-logo">
                        <img src="images/589828036_1222267966428855_4924886836244892648_n-removebg-preview.png" alt="Ridershub Logo">
                        <span class="footer-brand">RIDERSHUB</span>
                    </div>
                    <p class="footer-description">
                        Your premier destination for premium motorcycle helmets and gears. Ride safe, ride stylish.
                    </p>
                </div>

                <!-- Quick Links -->
                <div class="footer-col">
                    <h3 class="footer-title">QUICK LINKS</h3>
                    <ul class="footer-links">
                        <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                        <li><a href="#about"><i class="fas fa-chevron-right"></i> About Us</a></li>
                        <li><a href="javascript:void(0)" onclick="showProducts()"><i class="fas fa-chevron-right"></i> Products</a></li>
                        <li><a href="cart.php"><i class="fas fa-chevron-right"></i> Cart</a></li>
                        <li><a href="#featured"><i class="fas fa-chevron-right"></i> Categories</a></li>
                        <li><a href="#brands"><i class="fas fa-chevron-right"></i> Brands</a></li>
                    </ul>
                </div>

                <!-- Categories -->
                <div class="footer-col">
                    <h3 class="footer-title">HELMET TYPES</h3>
                    <ul class="footer-links">
                        <?php foreach($helmet_types as $type): ?>
                        <li><a href="javascript:void(0)" onclick="filterByTypeAndShow('<?= $type ?>')">
                            <i class="fas fa-chevron-right"></i> <?= strtoupper(str_replace('-', ' ', $type)) ?>
                        </a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Contact Info -->
                <div class="footer-col" id="contact">
                    <h3 class="footer-title">CONTACT US</h3>
                    <ul class="footer-contact">
                        <li>
                            <i class="fas fa-map-marker-alt"></i>
                            <span>43 paso de blas Valenzuela city</span>
                        </li>
                        <li>
                            <i class="fas fa-phone"></i>
                            <span>+63 956 966 3196</span>
                        </li>
                        <li>
                            <i class="fas fa-envelope"></i>
                            <span>kbridershub@gmail.com</span>
                        </li>
                        <li>
                            <i class="fas fa-clock"></i>
                            <span>Everyday: 9:00 AM - 8:00 PM</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Newsletter -->
            <div class="newsletter">
                <h3 class="newsletter-title">STAY UPDATED</h3>
                <p class="newsletter-text">Subscribe to get notified about new arrivals and exclusive offers</p>
                <form class="newsletter-form" onsubmit="event.preventDefault(); subscribeNewsletter()">
                    <input type="email" placeholder="Your email address" id="newsletterEmail" required>
                    <button type="submit">SUBSCRIBE</button>
                </form>
            </div>

            <!-- Bottom Bar -->
            <div class="footer-bottom">
                <div class="copyright">
                    &copy; <?= date('Y') ?> RIDERSHUB. All rights reserved.
                </div>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                    <a href="#">Shipping Info</a>
                    <a href="#">Returns</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
    // Global variables
    let currentBrand = "all";
    let currentType = "all";
    let cards = [];

    // Function to show products section
    function showProducts() {
        const productsSection = document.getElementById('products');
        productsSection.classList.add('visible');
        
        // Smooth scroll to products
        productsSection.scrollIntoView({ behavior: 'smooth' });
        
        // Show loading animation
        document.getElementById('loadingOverlay').classList.add('active');
        setTimeout(() => {
            document.getElementById('loadingOverlay').classList.remove('active');
        }, 500);
        
        window.location.hash = 'products';
    }

    // Function to filter by brand and show products
    function filterByBrandAndShow(brandSlug) {
        currentBrand = brandSlug;
        currentType = "all";
        showProducts();
        setTimeout(() => { filterProducts(); }, 300);
    }

    // Function to filter by type and show products
    function filterByTypeAndShow(type) {
        currentType = type;
        currentBrand = "all";
        showProducts();
        setTimeout(() => { filterProducts(); }, 300);
    }

    // Filter Products Function
    function filterProducts() {
        cards.forEach(card => {
            const cardBrand = card.dataset.brand;
            const cardType = card.dataset.helmetType;
            const matchBrand = currentBrand === "all" || cardBrand === currentBrand;
            const matchType = currentType === "all" || cardType === currentType;
            card.style.display = (matchBrand && matchType) ? "flex" : "none";
        });

        document.getElementById('loadingOverlay').classList.add('active');
        setTimeout(() => {
            document.getElementById('loadingOverlay').classList.remove('active');
        }, 300);
    }

    // Newsletter subscription
    function subscribeNewsletter() {
        const email = document.getElementById('newsletterEmail').value;
        Swal.fire({
            title: 'SUCCESS!',
            text: 'Thank you for subscribing to our newsletter!',
            icon: 'success',
            background: '#111',
            color: '#fff',
            confirmButtonColor: '#ff0000'
        });
        document.getElementById('newsletterEmail').value = '';
    }

    // Close modal function
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = "none";
        document.body.style.overflow = '';
        const stockInfo = document.querySelector('.stock-info-modal');
        if(stockInfo) stockInfo.remove();
    }

    // Add to Cart Function
    function addToCart(productId, productName, price, colors, sizes, stock) {
        if(stock <= 0) {
            Swal.fire({
                icon: 'error',
                title: 'Out of Stock',
                text: 'This item is currently out of stock.',
                background: '#111',
                color: '#fff',
                confirmButtonColor: '#ff0000'
            });
            return;
        }
        
        const colorArray = colors ? colors.split(',').map(c => c.trim()).filter(c => c) : ['Standard'];
        const sizeArray = sizes ? sizes.split(',').map(s => s.trim()).filter(s => s) : ['One Size'];
        
        document.getElementById('quickCartProductId').value = productId;
        document.getElementById('quickCartProductName').innerHTML = '<strong>' + productName + '</strong>';
        document.getElementById('quickCartPrice').innerHTML = '₱' + price.toLocaleString() + ' each';
        
        const colorSelect = document.getElementById('quickCartColor');
        colorSelect.innerHTML = '';
        colorArray.forEach(color => {
            const option = document.createElement('option');
            option.value = color;
            option.textContent = color;
            colorSelect.appendChild(option);
        });
        
        const sizeSelect = document.getElementById('quickCartSize');
        sizeSelect.innerHTML = '';
        sizeArray.forEach(size => {
            const option = document.createElement('option');
            option.value = size;
            option.textContent = size;
            sizeSelect.appendChild(option);
        });
        
        document.getElementById('quickCartQuantity').max = stock;
        document.getElementById('quickCartQuantity').value = 1;
        
        const stockInfo = document.createElement('small');
        stockInfo.style.display = 'block';
        stockInfo.style.color = stock <= 5 ? '#ff9900' : '#00ff00';
        stockInfo.style.marginTop = '5px';
        stockInfo.style.fontSize = '0.9rem';
        stockInfo.className = 'stock-info-modal';
        stockInfo.innerHTML = `<i class="fas fa-${stock <= 5 ? 'exclamation-triangle' : 'check-circle'}"></i> ${stock} units available`;
        
        const modalContent = document.querySelector('#quickCartModal .quick-cart-content');
        const existingInfo = modalContent.querySelector('.stock-info-modal');
        if(existingInfo) existingInfo.remove();
        modalContent.insertBefore(stockInfo, document.getElementById('quickCartForm'));
        
        document.getElementById('quickCartModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    // Validate stock before adding to cart
    function validateCartStock() {
        const quantity = parseInt(document.getElementById('quickCartQuantity').value);
        const maxStock = parseInt(document.getElementById('quickCartQuantity').max);
        
        if(quantity > maxStock) {
            Swal.fire({
                icon: 'error',
                title: 'Insufficient Stock',
                text: `Only ${maxStock} units available.`,
                background: '#111',
                color: '#fff',
                confirmButtonColor: '#ff0000'
            });
            return false;
        }
        return true;
    }

    // Update Cart Count
    function updateCartCount(count) {
        const navCartCount = document.getElementById('navCartCount');
        if(navCartCount) navCartCount.textContent = count;
    }

    // Function to handle URL parameters for filtering
    function getUrlParams() {
        const urlParams = new URLSearchParams(window.location.search);
        const brand = urlParams.get('brand');
        const type = urlParams.get('type');
        
        if (brand || type) {
            document.getElementById('products').classList.add('visible');
        }
        
        if (brand) {
            currentBrand = brand;
            filterProducts();
        } else if (type) {
            currentType = type;
            filterProducts();
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        // Initialize cards
        cards = document.querySelectorAll(".product-card");
        
        // DOM elements
        const modal = document.getElementById("reserveModal");
        const quickCartModal = document.getElementById("quickCartModal");
        const modalProductName = document.getElementById("modalProductName");
        const modalStockDisplay = document.getElementById("modalStockDisplay");
        const modalProductId = document.getElementById("modalProductId");
        const modalColor = document.getElementById("modalColor");
        const modalSize = document.getElementById("modalSize");
        const modalQuantity = document.getElementById("modalQuantity");
        const reservationForm = document.getElementById("reservationForm");
        const quickCartForm = document.getElementById("quickCartForm");
        const mobileMenuBtn = document.getElementById("mobileMenuBtn");
        const navMenu = document.getElementById("navMenu");
        const closeMenuBtn = document.getElementById("closeMenuBtn");
        const mobileSearchBtn = document.getElementById("mobileSearchBtn");
        const mobileSearchContainer = document.getElementById("mobileSearchContainer");
        const mobileSearchInput = document.getElementById("mobileSearchInput");
        const filterButtons = document.querySelectorAll(".filter-btn");
        const loadingOverlay = document.getElementById("loadingOverlay");
        const backToTopBtn = document.getElementById("backToTop");
        const nav = document.querySelector('.nav');
        
        // Set minimum date for pickup
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        const pickupDate = document.getElementById('pickupDate');
        if (pickupDate) {
            pickupDate.min = tomorrow.toISOString().split('T')[0];
            pickupDate.value = tomorrow.toISOString().split('T')[0];
        }
        
        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
            backToTopBtn.style.display = window.pageYOffset > 300 ? 'flex' : 'none';
        });
        
        // Mobile Menu Toggle
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                navMenu.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        }
        
        if (closeMenuBtn) {
            closeMenuBtn.addEventListener('click', () => {
                navMenu.classList.remove('active');
                document.body.style.overflow = '';
            });
        }
        
        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (navMenu && navMenu.classList.contains('active') && 
                    !navMenu.contains(e.target) && 
                    !mobileMenuBtn.contains(e.target)) {
                    navMenu.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });
        
        // Mobile dropdown toggle
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    const dropdown = toggle.closest('.dropdown');
                    dropdown.classList.toggle('active');
                }
            });
        });
        
        // Mobile Search Toggle
        if (mobileSearchBtn) {
            mobileSearchBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (mobileSearchContainer) {
                    mobileSearchContainer.style.display = mobileSearchContainer.style.display === 'block' ? 'none' : 'block';
                    if (mobileSearchContainer.style.display === 'block') {
                        mobileSearchInput.focus();
                    }
                }
            });
        }
        
        // Mobile Search Functionality
        if (mobileSearchInput) {
            mobileSearchInput.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase().trim();
                cards.forEach(card => {
                    const productName = card.getAttribute('data-name') || '';
                    const brandName = card.getAttribute('data-brand') || '';
                    const displayCard = productName.includes(searchTerm) || brandName.includes(searchTerm) || searchTerm === '';
                    card.style.display = displayCard ? 'flex' : 'none';
                });
            });
        }
        
        // Mobile Filter Buttons
        filterButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                filterButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                if (window.innerWidth <= 768) {
                    loadingOverlay.classList.add('active');
                    setTimeout(() => {
                        loadingOverlay.classList.remove('active');
                    }, 300);
                }
            });
        });
        
        // Back to Top Button
        if (backToTopBtn) {
            backToTopBtn.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
        
        // Detect helmet type from product name
        function detectHelmetTypeFromName(productName) {
            const name = productName.toLowerCase();
            if (name.includes('full')) return 'full-face';
            if (name.includes('modular')) return 'modular';
            if (name.includes('half')) return 'half-face';
            if (name.includes('open')) return 'open-face';
            if (name.includes('off')) return 'off-road';
            return 'full-face';
        }
        
        // Set helmet type if missing
        cards.forEach(card => {
            if (!card.dataset.helmetType || card.dataset.helmetType === "") {
                const productNameElement = card.querySelector('.product-name');
                const productName = productNameElement ? productNameElement.innerText : '';
                const helmetType = detectHelmetTypeFromName(productName);
                card.dataset.helmetType = helmetType;
            }
        });
        
        // Reservation Modal
        document.querySelectorAll('.reserve-btn').forEach(button => {
            button.addEventListener('click', function() {
                const stock = parseInt(this.getAttribute('data-stock')) || 0;
                
                modalProductName.innerText = "Item: " + (this.getAttribute('data-name') || 'Unknown');
                modalProductId.value = this.getAttribute('data-id') || 0;
                
                if(stock > 0) {
                    modalStockDisplay.innerText = "Current Stock: " + stock;
                    modalStockDisplay.className = stock <= 5 ? 'stock-tag low-stock' : 'stock-tag';
                    modalQuantity.max = stock;
                    modalQuantity.value = 1;
                    modalQuantity.disabled = false;
                    document.querySelector('#reservationForm .submit-res-btn').disabled = false;
                } else {
                    modalStockDisplay.innerText = "Out of Stock";
                    modalStockDisplay.className = 'out-of-stock-tag';
                    modalQuantity.max = 0;
                    modalQuantity.value = 0;
                    modalQuantity.disabled = true;
                    document.querySelector('#reservationForm .submit-res-btn').disabled = true;
                }
                
                // Colors
                const colors = (this.getAttribute('data-colors') || 'Standard').split(',').map(c => c.trim());
                modalColor.innerHTML = '';
                colors.forEach(c => {
                    let opt = document.createElement('option');
                    opt.value = opt.textContent = c;
                    modalColor.appendChild(opt);
                });
                
                // Sizes
                const sizes = (this.getAttribute('data-sizes') || 'One Size').split(',').map(s => s.trim());
                modalSize.innerHTML = '';
                sizes.forEach(s => {
                    let opt = document.createElement('option');
                    opt.value = opt.textContent = s;
                    modalSize.appendChild(opt);
                });
                
                modal.style.display = "block";
                document.body.style.overflow = 'hidden';
            });
        });
        
        // Quick Cart Form Submit
        if (quickCartForm) {
            quickCartForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if(!validateCartStock()) return;
                
                fetch('cart_handler.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        quickCartModal.style.display = "none";
                        document.body.style.overflow = '';
                        this.reset();
                        updateCartCount(data.cart_count);
                        
                        const stockInfo = document.querySelector('.stock-info-modal');
                        if(stockInfo) stockInfo.remove();
                        
                        Swal.fire({
                            title: 'SUCCESS!',
                            text: 'Item added to cart!',
                            icon: 'success',
                            background: '#111',
                            color: '#fff',
                            confirmButtonColor: '#ff0000',
                            showCancelButton: true,
                            confirmButtonText: 'VIEW CART',
                            cancelButtonText: 'CONTINUE'
                        }).then((result) => {
                            if (result.isConfirmed) window.location.href = 'cart.php';
                        });
                    } else {
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Error', 
                            text: data.message || 'Something went wrong!', 
                            background: '#111', 
                            color: '#fff',
                            confirmButtonColor: '#ff0000'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: 'Network error. Please try again.', 
                        background: '#111', 
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                });
            });
        }
        
        // Reservation Form Submit
        if (reservationForm) {
            reservationForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (parseInt(modalQuantity.value) > parseInt(modalQuantity.max) || parseInt(modalQuantity.max) === 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Out of Stock',
                        text: 'Item unavailable for reservation.',
                        background: '#111', 
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                    return;
                }
                
                fetch('cart_handler.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        modal.style.display = "none";
                        document.body.style.overflow = '';
                        this.reset();
                        
                        setTimeout(() => {
                            location.reload();
                        }, 3000);
                        
                        Swal.fire({
                            title: 'RESERVATION SUCCESS!',
                            html: `<div style="text-align: center;">
                                <p>Show this ticket to the staff:</p>
                                <h1 style="color: red; font-size: 32px; border: 2px dashed red; padding: 10px;">${data.ticket}</h1>
                                <p style="margin-top: 15px;">Please pick up on: ${data.pickup_date}</p>
                            </div>`,
                            icon: 'success',
                            background: '#111',
                            color: '#fff',
                            confirmButtonColor: '#ff0000',
                            confirmButtonText: 'OK'
                        });
                    } else {
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Error', 
                            text: data.message || 'Reservation failed!', 
                            background: '#111', 
                            color: '#fff',
                            confirmButtonColor: '#ff0000'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: 'Network error. Please try again.', 
                        background: '#111', 
                        color: '#fff',
                        confirmButtonColor: '#ff0000'
                    });
                });
            });
        }
        
        // Close modals when clicking outside
        window.onclick = (e) => { 
            if (e.target == modal || e.target == quickCartModal) {
                modal.style.display = "none";
                quickCartModal.style.display = "none";
                document.body.style.overflow = '';
                const stockInfo = document.querySelector('.stock-info-modal');
                if(stockInfo) stockInfo.remove();
            }
        };
        
        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                modal.style.display = "none";
                quickCartModal.style.display = "none";
                document.body.style.overflow = '';
                const stockInfo = document.querySelector('.stock-info-modal');
                if(stockInfo) stockInfo.remove();
                if (window.innerWidth <= 768) navMenu.classList.remove('active');
            }
        });
        
        // Responsive adjustments
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                navMenu.classList.remove('active');
                document.body.style.overflow = '';
                if (mobileSearchContainer) mobileSearchContainer.style.display = 'none';
                document.querySelectorAll('.dropdown').forEach(d => d.classList.remove('active'));
            }
        });
        
        // Check for URL parameters
        getUrlParams();
        
        // Check for hash in URL
        if (window.location.hash === '#products') {
            document.getElementById('products').classList.add('visible');
        }

        // Animated Stats Counter
        function animateStats() {
            const stats = document.querySelectorAll('.stat-number');
            stats.forEach(stat => {
                const target = stat.getAttribute('data-target');
                if (!target) return;
                
                let current = 0;
                const increment = target / 50;
                const updateStat = () => {
                    if (current < target) {
                        current += increment;
                        if (current > target) current = target;
                        stat.textContent = Math.floor(current) + (target == '24' ? '/7' : '+');
                        requestAnimationFrame(updateStat);
                    } else {
                        stat.textContent = target + (target == '24' ? '/7' : '+');
                    }
                };
                updateStat();
            });
        }

        // Trigger stats animation when about section is in view
        const aboutSection = document.getElementById('about');
        if (aboutSection) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        animateStats();
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            observer.observe(aboutSection);
        }
    });
    </script>
</body>
</html>