<?php
// api/change_password.php - Secure User Password Updater
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id = (int)$_SESSION['user_id'];
$cur_pass = trim($_POST['current_password'] ?? '');
$new_pass = trim($_POST['new_password'] ?? '');

if (empty($cur_pass) || empty($new_pass)) {
    echo json_encode(['status' => false, 'message' => 'All password fields are required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'User not found.']);
        exit;
    }

    $db_pass = $user['password'];
    $valid = false;
    if (password_verify($cur_pass, $db_pass) || md5($cur_pass) === $db_pass || $cur_pass === $db_pass) {
        $valid = true;
    }

    if (!$valid) {
        echo json_encode(['status' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$new_hash, $user_id]);

    echo json_encode(['status' => true, 'message' => 'Password updated successfully!']);
} catch(Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Error updating password: ' . $e->getMessage()]);
}
?>