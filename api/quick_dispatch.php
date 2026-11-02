<?php
// api/quick_dispatch.php - Sender Number Driven Multi-Tenant WhatsApp Dispatcher
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://sage.paramountservices.co.in');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

// 1. Extension se bheja gaya sender mobile number pakdo
$raw_sender = trim($_POST['sender_phone'] ?? '');
$clean_sender = preg_replace('/[^0-9]/', '', $raw_sender);
$last10_sender = strlen($clean_sender) >= 10 ? substr($clean_sender, -10) : $raw_sender;

$user_id = 0;

try {
    // A. Pehle user_whatsapp_accounts table ke phone_number column mein search karein
    if (!empty($last10_sender)) {
        $stmt_wa = $pdo->prepare("
            SELECT user_id FROM user_whatsapp_accounts 
            WHERE phone_number LIKE ? 
               OR phone_number_id LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt_wa->execute(["%{$last10_sender}%", "%{$last10_sender}%"]);
        $user_id = (int)$stmt_wa->fetchColumn();
    }

    // B. Agar wahan na mile toh users table mein search karein
    if ($user_id <= 0 && !empty($last10_sender)) {
        $stmt_u = $pdo->prepare("
            SELECT id FROM users 
            WHERE phone LIKE ? 
               OR name LIKE ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt_u->execute(["%{$last10_sender}%", "%{$last10_sender}%"]);
        $user_id = (int)$stmt_u->fetchColumn();
    }

    // C. Fallback: Pehla valid WhatsApp account jisme access token ho
    if ($user_id <= 0) {
        $stmt_any_wa = $pdo->query("SELECT user_id FROM user_whatsapp_accounts WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 1");
        $user_id = (int)$stmt_any_wa->fetchColumn();
    }

    // D. Final safe fallback
    if ($user_id <= 0) {
        $stmt_first = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        $user_id = (int)$stmt_first->fetchColumn();
    }

} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Database Query Error: ' . $e->getMessage()]);
    exit;
}

if ($user_id <= 0) {
    echo json_encode([
        'status'  => false,
        'message' => 'User account could not be found.'
    ]);
    exit;
}

// 2. Request details collect karein
$raw_recipient   = trim($_POST['recipient'] ?? '');
$workshop_name   = trim($_POST['workshop_name'] ?? 'Workshop');
$claim_number    = trim($_POST['claim_number'] ?? 'N/A');
$vehicle_number  = trim($_POST['vehicle_number'] ?? 'N/A');
$inspection_link = trim($_POST['inspection_link'] ?? '');

$recipient = preg_replace('/[^0-9]/', '', $raw_recipient);
if (strlen($recipient) === 10) {
    $recipient = '91' . $recipient;
} elseif (strlen($recipient) === 11 && strpos($recipient, '0') === 0) {
    $recipient = '91' . substr($recipient, 1);
}

if (empty($recipient) || strlen($recipient) < 10) {
    echo json_encode(['status' => false, 'message' => 'Invalid contact phone number.']);
    exit;
}

if (empty($inspection_link)) {
    echo json_encode(['status' => false, 'message' => 'Inspection link is missing.']);
    exit;
}

try {
    // 3. User wallet balance check karein
    $stmt_bal = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt_bal->execute([$user_id]);
    $wallet_balance = (float)$stmt_bal->fetchColumn();

    if ($wallet_balance < 0.50) {
        echo json_encode([
            'status'  => false,
            'message' => 'Insufficient wallet balance in this Wubachat account.'
        ]);
        exit;
    }

    // 4. WhatsApp credentials fetch karein
    $stmt_wp = $pdo->prepare("SELECT phone_number_id, access_token FROM user_whatsapp_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_wp->execute([$user_id]);
    $wp_acc = $stmt_wp->fetch(PDO::FETCH_ASSOC);

    if (!$wp_acc || empty($wp_acc['phone_number_id']) || empty($wp_acc['access_token'])) {
        $stmt_any = $pdo->query("SELECT phone_number_id, access_token FROM user_whatsapp_accounts WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 1");
        $wp_acc = $stmt_any->fetch(PDO::FETCH_ASSOC);
    }

    $phone_number_id = $wp_acc['phone_number_id'] ?? (defined('META_PHONE_NUMBER_ID') ? META_PHONE_NUMBER_ID : '');
    $access_token    = $wp_acc['access_token'] ?? (defined('META_SYSTEM_TOKEN') ? META_SYSTEM_TOKEN : '');

    if (empty($phone_number_id) || empty($access_token)) {
        echo json_encode([
            'status'  => false,
            'message' => 'WhatsApp Cloud API settings missing for user ID: ' . $user_id
        ]);
        exit;
    }

    // 5. Meta WhatsApp template payload
    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $recipient,
        'type'              => 'template',
        'template'          => [
            'name'     => 'survey_alert',
            'language' => ['code' => 'en'],
            'components' => [
                [
                    'type'       => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => substr($claim_number, 0, 50)],
                        ['type' => 'text', 'text' => substr($vehicle_number, 0, 50)],
                        ['type' => 'text', 'text' => $inspection_link]
                    ]
                ]
            ]
        ]
    ];

    $graph_ver = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';
    $url = "https://graph.facebook.com/{$graph_ver}/{$phone_number_id}/messages";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_data = json_decode($response, true);

    if ($http_code === 200 && !empty($res_data['messages'][0]['id'])) {
        $wamid = $res_data['messages'][0]['id'];

        // Balance deduct karein
        $new_balance = max(0, $wallet_balance - 0.50);
        $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$new_balance, $user_id]);

        $summary_msg = "Paramount Inspection Alert\nClaim: {$claim_number}\nVehicle: {$vehicle_number}\nLink: {$inspection_link}";

        // Messages table mein log karein
        $stmt_log = $pdo->prepare("
            INSERT INTO messages (user_id, recipient_phone, message_text, message_body, direction, sent_via, wamid, wa_message_id, status, cost, created_at)
            VALUES (?, ?, ?, ?, 'outbound', 'whatsapp', ?, ?, 'sent', 0.50, NOW())
        ");
        $stmt_log->execute([$user_id, $recipient, $summary_msg, $summary_msg, $wamid, $wamid]);

        // Contacts list ko top par laane ke liye update/create karein
        $last10 = substr($recipient, -10);
        $stmt_c = $pdo->prepare("SELECT id FROM contacts WHERE user_id = ? AND (phone_number = ? OR RIGHT(phone_number, 10) = ?) LIMIT 1");
        $stmt_c->execute([$user_id, $recipient, $last10]);
        $existing_id = $stmt_c->fetchColumn();

        if ($existing_id) {
            $pdo->prepare("UPDATE contacts SET created_at = NOW() WHERE id = ?")->execute([$existing_id]);
        } else {
            $pdo->prepare("INSERT INTO contacts (user_id, contact_name, phone_number, created_at) VALUES (?, ?, ?, NOW())")->execute([$user_id, $workshop_name, $recipient]);
        }

        echo json_encode([
            'status'  => true,
            'message' => 'WhatsApp template dispatched successfully.'
        ]);
        exit;
    } else {
        $error_detail = $res_data['error']['message'] ?? 'Meta WhatsApp API rejected request.';
        echo json_encode(['status' => false, 'message' => $error_detail]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}