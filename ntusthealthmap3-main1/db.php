<?php
// db.php - PDO connection (edit credentials to match your XAMPP MySQL)
session_start();
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'ntust_healthmap');
define('DB_USER', 'root');
define('DB_PASS', ''); // update if you set a root password

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    // In production, don't echo details. For development, helpful to show.
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}
