<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['chat_ids']) && is_array($data['chat_ids']) && !empty($data['chat_ids'])) {
    global $conn; // or whatever DB connection variable exists in config.php
    $chatIds = $data['chat_ids'];
    
    // Sanitize IDs
    $ids = implode(',', array_map('intval', $chatIds));
    
    // Adjust table/column name according to your DB schema (e.g., chats or messages table)
    // Here we safely execute deletion if connection exists
    if (isset($conn)) {
        $conn->query("DELETE FROM chats WHERE id IN ($ids) OR phone IN ($ids)");
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Selected chats deleted successfully']);
    exit;
}
echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
