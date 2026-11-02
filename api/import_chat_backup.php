<?php
// api/import_chat_backup.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id   = $_SESSION['user_id'];
$recipient = preg_replace('/[^0-9]/', '', $_POST['recipient'] ?? '');

if (empty($recipient) || strlen($recipient) < 10) {
    echo json_encode(['status' => false, 'message' => 'Please select or enter the target mobile number for this chat backup.']);
    exit;
}

if (empty($_FILES['backup_file']['tmp_name'])) {
    echo json_encode(['status' => false, 'message' => 'Please upload a valid exported WhatsApp .txt chat file.']);
    exit;
}

$file_content = file_get_contents($_FILES['backup_file']['tmp_name']);
if (!$file_content) {
    echo json_encode(['status' => false, 'message' => 'Unable to read backup file.']);
    exit;
}

$lines = explode("\n", $file_content);
$imported_messages = 0;

try {
    $stmt_insert = $pdo->prepare("
        INSERT INTO messages (user_id, recipient_phone, message_text, direction, status, cost) 
        VALUES (?, ?, ?, ?, 'delivered', 0.00)
    ");

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // WhatsApp Export Regex formats:
        // Format: [dd/mm/yy, hh:mm:ss] Sender: Message OR dd/mm/yyyy, hh:mm - Sender: Message
        if (preg_match('/^(?:\[?(\d{1,2}\/\d{1,2}\/\d{2,4}),?\s+(\d{1,2}:\d{2}(?::\d{2})?(?:\s?[APMapm]{2})?)\]?\s*-?\s*)([^:]+):\s*(.+)$/u', $line, $matches)) {
            $sender_name = trim($matches[3]);
            $msg_body    = trim($matches[4]);

            // Direction heuristic: If sender contains "You" or matches current user name -> outbound, else inbound
            $direction = (stripos($sender_name, 'You') !== false || stripos($sender_name, 'Self') !== false) ? 'outbound' : 'inbound';

            $stmt_insert->execute([$user_id, $recipient, $msg_body, $direction]);
            $imported_messages++;
        }
    }

    echo json_encode([
        'status'  => true,
        'message' => "Backup successfully restored! $imported_messages messages imported into chat history."
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Import failed: ' . $e->getMessage()]);
}
?>