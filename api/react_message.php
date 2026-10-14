<?php
// api/react_message.php - Live WhatsApp Cloud API & Database Reaction Dispatcher
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id    = (int)$_SESSION['user_id'];
$message_id = (int)($_POST['message_id'] ?? 0);
$reaction   = trim($_POST['reaction'] ?? '');

if (!$message_id) {
    echo json_encode(['status' => false, 'message' => 'Message ID is required']);
    exit;
}

try {
    // 1. Fetch message details from database
    $stmt_msg = $pdo->prepare("SELECT recipient_phone, wamid FROM messages WHERE id = ? LIMIT 1");
    $stmt_msg->execute([$message_id]);
    $msg_row = $stmt_msg->fetch();

    if (!$msg_row) {
        echo json_encode(['status' => false, 'message' => 'Message not found']);
        exit;
    }

    $recipient_phone = $msg_row['recipient_phone'] ?? '';
    $wamid           = $msg_row['wamid'] ?? '';

    // 2. Fetch active Meta WhatsApp account credentials
    $stmt_waba = $pdo->prepare("SELECT phone_number_id, access_token FROM user_whatsapp_accounts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $stmt_waba->execute([$user_id]);
    $waba_row = $stmt_waba->fetch();

    $phone_number_id = !empty($waba_row['phone_number_id']) ? $waba_row['phone_number_id'] : (defined('META_PHONE_NUMBER_ID') ? META_PHONE_NUMBER_ID : '');
    $access_token    = !empty($waba_row['access_token']) ? $waba_row['access_token'] : (defined('META_SYSTEM_TOKEN') ? META_SYSTEM_TOKEN : '');
    $graph_version   = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';

    // 3. Dispatch Reaction to Meta Cloud API if WhatsApp Message ID (wamid) exists
    if (!empty($wamid) && !empty($phone_number_id) && !empty($access_token) && !empty($recipient_phone)) {
        $meta_url = "https://graph.facebook.com/{$graph_version}/{$phone_number_id}/messages";
        
        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type"    => "individual",
            "to"                => $recipient_phone,
            "type"              => "reaction",
            "reaction"          => [
                "message_id" => $wamid,
                "emoji"      => $reaction
            ]
        ];

        $ch = curl_init($meta_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        @curl_exec($ch);
        curl_close($ch);
    }

    // 4. Update Database Reaction State
    $stmt = $pdo->prepare("UPDATE messages SET reaction = ? WHERE id = ?");
    $stmt->execute([$reaction, $message_id]);

    echo json_encode(['status' => true, 'reaction' => $reaction]);
} catch(Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>