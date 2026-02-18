// Add to Cart Feature
const buttons = document.querySelectorAll('button');
buttons.forEach(btn => {
btn.addEventListener('click', () => {
alert('Item added to cart!');
});
});
function logout() {
    // clear session
    localStorage.removeItem("isLoggedIn");
    localStorage.removeItem("userRole");

    // redirect
    window.location.href = "Landingpage.html";
}
/* ================= NAV ACTIVE ON CLICK ================= */

const navLinks = document.querySelectorAll(".nav-menu li a");

navLinks.forEach(link => {
    link.addEventListener("click", function () {
        navLinks.forEach(l => l.classList.remove("active"));
        this.classList.add("active");
    });
});

/* ===== SHARED JAVASCRIPT FUNCTIONS ===== */

// Validate email format
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Show notification function
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.notification');
    existingNotifications.forEach(notification => notification.remove());
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    
    // Set icon based on type
    let icon = 'info-circle';
    if (type === 'success') icon = 'check-circle';
    if (type === 'error') icon = 'exclamation-circle';
    if (type === 'warning') icon = 'exclamation-triangle';
    
    notification.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" class="notification-close">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Add styles if not already present
    if (!document.getElementById('notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            .notification {
                position: fixed;
                top: 80px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 10px;
                color: white;
                display: flex;
                align-items: center;
                gap: 10px;
                z-index: 9999;
                animation: slideInRight 0.3s ease;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                min-width: 300px;
                max-width: 400px;
            }
            .notification.success {
                background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
                border-left: 4px solid #27ae60;
            }
            .notification.error {
                background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
                border-left: 4px solid #c0392b;
            }
            .notification.warning {
                background: linear-gradient(135deg, #f39c12 0%, #d35400 100%);
                border-left: 4px solid #d35400;
            }
            .notification.info {
                background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
                border-left: 4px solid #2980b9;
            }
            .notification-close {
                margin-left: auto;
                background: none;
                border: none;
                color: white;
                cursor: pointer;
                opacity: 0.7;
                transition: opacity 0.3s ease;
            }
            .notification-close:hover {
                opacity: 1;
            }
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    }
    
    // Add to body
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

// Logout function for regular users
function logoutUser() {
    if (confirm('Are you sure you want to logout?')) {
        localStorage.removeItem('isLoggedIn');
        localStorage.removeItem('userRole');
        localStorage.removeItem('userName');
        localStorage.removeItem('userEmail');
        showNotification('Logged out successfully!', 'success');
        setTimeout(() => {
            window.location.href = 'login.html';
        }, 1000);
    }
}

// Check if user is logged in (for protected pages)
function checkLogin() {
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    const userRole = localStorage.getItem('userRole');
    
    if (!isLoggedIn || isLoggedIn !== 'true') {
        return false;
    }
    
    return { isLoggedIn: true, role: userRole };
}

// Redirect to login if not authenticated
function requireAuth() {
    if (!checkLogin()) {
        window.location.href = 'login.html';
        return false;
    }
    return true;
}

// Redirect to appropriate dashboard based on role
function redirectByRole() {
    const auth = checkLogin();
    if (!auth) return;
    
    if (auth.role === 'admin') {
        window.location.href = 'admin-dashboard.html';
    } else {
        window.location.href = 'user-dashboard.html'; // Create this page
    }
}

// Session timeout function (30 minutes)
function setupSessionTimeout() {
    let timeout;
    const timeoutDuration = 30 * 60 * 1000; // 30 minutes
    
    function resetTimeout() {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            if (confirm('Your session is about to expire. Do you want to stay logged in?')) {
                resetTimeout();
            } else {
                logoutUser();
            }
        }, timeoutDuration);
    }
    
    // Reset timeout on user activity
    ['click', 'mousemove', 'keypress', 'scroll'].forEach(event => {
        document.addEventListener(event, resetTimeout);
    });
    
    resetTimeout(); // Start the timeout
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Setup session timeout if user is logged in
    if (checkLogin()) {
        setupSessionTimeout();
    }
});

/* ================= REGISTER ================= */
document.getElementById("registerForm")?.addEventListener("submit", e => {
    e.preventDefault();

    const user = {
        fullname: fullname.value,
        email: email.value,
        password: password.value
    };

    localStorage.setItem("kbUser", JSON.stringify(user));
    alert("Registered successfully!");
    window.location.href = "login.html";
});

/* ================= USER LOGIN ================= */
document.getElementById("loginForm")?.addEventListener("submit", e => {
    e.preventDefault();

    const user = JSON.parse(localStorage.getItem("kbUser"));

    if (!user) return alert("No user found!");

    if (
        loginEmail.value === user.email &&
        loginPassword.value === user.password
    ) {
        localStorage.setItem("isLoggedIn", "true");
        localStorage.setItem("userRole", "user");
        window.location.href = "index.html";
    } else {
        alert("Invalid login!");
    }
});

/* ================= ADMIN LOGIN ================= */
document.getElementById("adminLoginForm")?.addEventListener("submit", e => {
    e.preventDefault();

    if (admin_id.value === "admin" && admin_password.value === "admin123") {
        localStorage.setItem("isLoggedIn", "true");
        localStorage.setItem("userRole", "admin");
        window.location.href = "admin-dashboard.html";
    } else {
        alert("Invalid admin credentials");
    }
});

/* ================= ADMIN SESSION CHECK ================= */
if (window.location.pathname.includes("admin-dashboard")) {
    if (
        localStorage.getItem("isLoggedIn") !== "true" ||
        localStorage.getItem("userRole") !== "admin"
    ) {
        alert("Access denied");
        window.location.href = "admin-login.html";
    }
}

/* ================= LOGOUT ================= */
function logoutAdmin() {
    if (confirm("Logout admin?")) {
        localStorage.clear();
        window.location.href = "admin_login.html";
    }
}
