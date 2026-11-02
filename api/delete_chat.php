<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['chat_ids']) && is_array($data['chat_ids']) && !empty($data['chat_ids'])) {
    $chatPhones = $data['chat_ids'];
    
    try {
        foreach ($chatPhones as $phone) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (empty($cleanPhone)) continue;
            
            // Delete messages for this recipient and user
            $stmtM = $pdo->prepare("DELETE FROM messages WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone LIKE ?)");
            $stmtM->execute([$user_id, $cleanPhone, '%' . $cleanPhone]);
            
            // Delete contact entry if exists
            $stmtC = $pdo->prepare("DELETE FROM contacts WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?)");
            $stmtC->execute([$user_id, $cleanPhone, '%' . $cleanPhone]);
        }
        echo json_encode(['status' => 'success', 'message' => 'Selected conversations deleted successfully']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
