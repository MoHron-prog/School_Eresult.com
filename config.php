<?php
// config.php - Database Configuration
session_start();



// Error handling - enable for development, disable for production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database credentials - Update these for your environment
define('DB_HOST', 'localhost');
define('DB_NAME', 'school_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create database connection
function getPDOConnection()
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // Log error securely (in production, log to file instead of displaying)
        error_log("Database connection failed: " . $e->getMessage());

        // User-friendly error message
        if (ini_get('display_errors')) {
            die("Database connection failed. Please try again later or contact administrator.");
        } else {
            die("System error. Please contact administrator.");
        }
    }
}

// Global PDO connection (optional, use getPDOConnection() function for better control)
try {
    $pdo = getPDOConnection();
} catch (Exception $e) {
    // Handle connection failure gracefully
    $pdo = null;
}

// Check if user is logged in helper function
function isLoggedIn()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check user role helper function
function hasRole($allowed_roles)
{
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['role'], (array)$allowed_roles);
}

// Redirect if not logged in
function requireLogin()
{
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit;
    }
}

// Redirect if not authorized
function requireRole($allowed_roles)
{
    requireLogin();
    if (!hasRole($allowed_roles)) {
        header("HTTP/1.1 403 Forbidden");
        die("<h2>Access Denied</h2><p>You do not have permission to access this page.</p>");
    }
}
