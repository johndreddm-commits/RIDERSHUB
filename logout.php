<?php
session_start();

// Save role before destroying session
$role = $_SESSION['role'] ?? null;

// Clear session
session_unset();
session_destroy();

// Redirect based on role
if ($role === 'admin') {
    header("Location: admin_login.php");
} else {
    header("Location: login.php");
}
exit;
