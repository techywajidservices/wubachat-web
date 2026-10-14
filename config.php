<?php
// Session check & Timezone
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'u670020116_wubachat_user');
define('DB_PASS', 'Wubachat@696095');
define('DB_NAME', 'u670020116_wubachat_db');

// Admin Notification Email Configuration
define('ADMIN_NOTIFICATION_EMAIL', 'support@wubachat.com');

// Meta WhatsApp Cloud API Configuration
define('META_APP_ID', '1371744501215031');
define('META_APP_SECRET', 'ed49ef11d295321487aab8d1f75d779a');
define('META_REDIRECT_URI', 'https://wubachat.com/auth/callback.php');
define('META_GRAPH_VERSION', 'v20.0');

// Production Live Registered Meta IDs & Permanent System User Token
define('META_PHONE_NUMBER_ID', '1339376939248300');
define('META_WABA_ID', '2553703311722771');
define('META_SYSTEM_TOKEN', 'EAATfmCe3rzcBSUY3ZBwOdvUYAMXSe3OEYncsCAQ0XI3e9YZB9zbZAxqbtulU9iFiJaaDxamAEo9UUtTwFlBw0alUdsobZCkgEpbBDPxnolfzwDnZCPW8Y3Gv95HVhGjnlQzv2lGZBkRimbehDamQi9FX4ZCe72YP6o8A2YPb5zLCJSLSZB2GeSZB3j6Du1QPaxAZDZD');

// Webhook Handshake Verification Tokens
define('META_VERIFY_TOKEN', 'wubachat_secure_token_2026');
define('META_WEBHOOK_VERIFY_TOKEN', 'wubachat_secure_token_2026');

// Meta Approved Default Template Settings (Outside 24-hr session)
define('META_DEFAULT_TEMPLATE', 'wubachat_general_alert');
define('META_DEFAULT_TEMPLATE_LANG', 'en');

// Fast2SMS Gateway Configuration
define('FAST2SMS_API_KEY', 'vm2d8NZsI73EMbg9jifz4SYkXPF0AOlHyruRVhCoQ5G6Taq1JtyIn12zrlG8aFQbVdwYSExMfW4XseTu');

// 1. MySQLi Connection Instance ($conn)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Database MySQLi Connection Failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// 2. PDO Connection Instance ($pdo)
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Database PDO Connection Failed: " . $e->getMessage());
}
?>