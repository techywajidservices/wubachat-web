<?php
// api/search_messages.php - Fast Deep Keyword Search Engine
require_once __DIR__ . '/../config.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
session_write_close(); // Instant release taaki dashboard freeze na ho

$q = trim($_GET['q'] ?? '');

if (strlen($q) === 0) {
    echo json_encode(['status' => true, 'results' => []]);
    exit;
}

try {
    // Fast Direct Search: messages table se text ya phone match karein
    $searchTerm = '%' . $q . '%';
    
    $stmt = $pdo->prepare("
        SELECT 
            m.id AS msg_id, 
            m.recipient_phone, 
            m.message_text, 
            m.direction, 
            m.status, 
            m.created_at, 
            COALESCE(c.contact_name, c.name, CONCAT('+', m.recipient_phone)) AS contact_name
        FROM messages m
        LEFT JOIN contacts c ON c.user_id = m.user_id 
             AND (c.phone_number = m.recipient_phone OR RIGHT(c.phone_number, 10) = RIGHT(m.recipient_phone, 10))
        WHERE (m.user_id = ? OR m.user_id = 1)
          AND (
              m.message_text LIKE ? 
              OR m.recipient_phone LIKE ?
          )
        ORDER BY m.id DESC 
        LIMIT 40
    ");

    $stmt->execute([$user_id, $searchTerm, $searchTerm]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'  => true,
        'query'   => $q,
        'results' => $rows
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status'  => false,
        'message' => 'Search error: ' . $e->getMessage()
    ]);
}
?>