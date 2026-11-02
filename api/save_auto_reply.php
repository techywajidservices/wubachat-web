<?php
// api/save_auto_reply.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id       = $_SESSION['user_id'];
$is_enabled    = isset($_POST['is_enabled']) && $_POST['is_enabled'] == '1' ? 1 : 0;
$reply_message = trim($_POST['reply_message'] ?? '');

try {
    $stmt = $pdo->prepare("
        INSERT INTO auto_replies (user_id, is_enabled, reply_message) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            is_enabled = VALUES(is_enabled),
            reply_message = VALUES(reply_message)
    ");
    $stmt->execute([$user_id, $is_enabled, $reply_message]);

    echo json_encode([
        'status'     => true,
        'message'    => $is_enabled ? 'Auto-Reply bot activated successfully!' : 'Auto-Reply bot turned OFF.',
        'is_enabled' => $is_enabled
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>