<?php
// api/delete_account.php - Production Complete Account & Data Purge
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id  = (int)$_SESSION['user_id'];
$password = $_POST['password'] ?? '';

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        echo json_encode(['status' => false, 'message' => 'Incorrect account password. Deletion aborted.']);
        exit;
    }

    $u_phone = $user['phone'] ?? ($user['mobile'] ?? '');
    $u_name  = $user['name'] ?? ($user['full_name'] ?? 'User');
    $u_email = $user['email'] ?? '';
    $u_bal   = $user['wallet_balance'] ?? 0.00;

    // 1. Safe Archival to Deleted Users Log
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `deleted_users_log` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `original_user_id` INT NULL,
                `name` VARCHAR(150) NULL,
                `email` VARCHAR(150) NULL,
                `phone` VARCHAR(50) NULL,
                `deleted_balance` DECIMAL(10,2) DEFAULT 0.00,
                `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $stmt_archive = $pdo->prepare("
            INSERT INTO deleted_users_log (original_user_id, name, email, phone, deleted_balance, deleted_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt_archive->execute([$user_id, $u_name, $u_email, $u_phone, $u_bal]);
    } catch(Exception $ex) {}

    // 2. Safe Activity Log Entry
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $pdo->prepare("INSERT INTO activity_logs (user_id, phone, activity_type, description, ip_address) VALUES (?, ?, 'DELETE_ACCOUNT', 'User requested permanent account deletion', ?)")
            ->execute([$user_id, $u_phone, $ip]);
    } catch(Exception $ex) {}

    // 3. Complete Data Clean-up (Airtight Data Deletion)
    $pdo->prepare("DELETE FROM messages WHERE user_id = ?")->execute([$user_id]);
    
    try {
        $pdo->prepare("DELETE FROM chat_messages WHERE user_id = ?")->execute([$user_id]);
    } catch(Exception $ex) {}

    $pdo->prepare("DELETE FROM contacts WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM auto_replies WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM user_whatsapp_accounts WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);

    // Clear session & tokens
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    echo json_encode(['status' => true, 'message' => 'Account deleted successfully. You can re-register anytime.']);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>