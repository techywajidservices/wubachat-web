<?php
// api/send_message.php - Multi-Tenant WhatsApp & Wubachat Dispatcher with survey_alert Template Engine
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id        = (int)$_SESSION['user_id'];
$raw_recipient  = preg_replace('/[^0-9]/', '', $_POST['recipient'] ?? '');
$message        = trim($_POST['message'] ?? '');
$reply_to_wamid = trim($_POST['reply_to_wamid'] ?? '');
$sent_via       = strtolower(trim($_POST['sent_via'] ?? 'wubachat'));
$mode           = trim($_POST['mode'] ?? 'text');

if (!in_array($sent_via, ['wubachat', 'whatsapp'])) {
    $sent_via = 'wubachat';
}

if (empty($raw_recipient)) {
    echo json_encode(['status' => false, 'message' => 'Please provide a valid mobile number.']);
    exit;
}

$recipient = $raw_recipient;
if (strlen($recipient) === 10) {
    $recipient = '91' . $recipient;
} elseif (strlen($recipient) === 11 && strpos($recipient, '0') === 0) {
    $recipient = '91' . substr($recipient, 1);
}

$last10 = substr($recipient, -10);

if ($mode !== 'template' && empty($message) && empty($_POST['media_url']) && empty($_FILES['file'])) {
    echo json_encode(['status' => false, 'message' => 'Message content cannot be empty.']);
    exit;
}

function executeMetaCurl($url, $payload, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'data' => json_decode($res, true), 'raw' => $res];
}

try {
    $stmtBlock = $pdo->prepare("SELECT id FROM blocked_contacts WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?) LIMIT 1");
    $stmtBlock->execute([$user_id, $recipient, "%{$last10}%"]);
    if ($stmtBlock->fetch()) {
        echo json_encode(['status' => false, 'message' => 'You have blocked this contact. Unblock to send messages.']);
        exit;
    }

    $stmt_sender = $pdo->prepare("SELECT phone, wallet_balance FROM users WHERE id = ? LIMIT 1");
    $stmt_sender->execute([$user_id]);
    $sender_user = $stmt_sender->fetch();
    $sender_phone = preg_replace('/[^0-9]/', '', $sender_user['phone'] ?? '');
    $current_balance = (float)($sender_user['wallet_balance'] ?? 0.00);

    // ==========================================
    // ROUTE A: WUBACHAT INTERNAL (FREE / ₹0)
    // ==========================================
    if ($sent_via === 'wubachat') {
        $internal_msg_id = 'wuba_' . time() . '_' . rand(1000, 9999);

        $stmt_ins = $pdo->prepare("
            INSERT INTO messages (user_id, recipient_phone, message_text, message_body, direction, sent_via, status, wamid, wa_message_id, reply_to_wamid, cost, created_at) 
            VALUES (?, ?, ?, ?, 'outbound', 'wubachat', 'delivered', ?, ?, ?, 0.00, NOW())
        ");
        $stmt_ins->execute([
            $user_id,
            $recipient,
            $message,
            $message,
            $internal_msg_id,
            $internal_msg_id,
            (!empty($reply_to_wamid) ? $reply_to_wamid : null)
        ]);

        $stmt_rcpt_user = $pdo->prepare("SELECT id FROM users WHERE phone = ? OR phone LIKE ? LIMIT 1");
        $stmt_rcpt_user->execute([$recipient, "%{$last10}%"]);
        $rcpt_account = $stmt_rcpt_user->fetch();

        if ($rcpt_account && !empty($sender_phone)) {
            $rcpt_uid = (int)$rcpt_account['id'];
            $stmt_rcpt_inbox = $pdo->prepare("
                INSERT INTO messages (user_id, recipient_phone, message_text, message_body, direction, sent_via, status, wamid, wa_message_id, reply_to_wamid, cost, created_at) 
                VALUES (?, ?, ?, ?, 'inbound', 'wubachat', 'unread', ?, ?, ?, 0.00, NOW())
            ");
            $stmt_rcpt_inbox->execute([
                $rcpt_uid,
                $sender_phone,
                $message,
                $message,
                $internal_msg_id,
                $internal_msg_id,
                (!empty($reply_to_wamid) ? $reply_to_wamid : null)
            ]);
        }

        echo json_encode([
            'status'         => true,
            'message'        => 'Wubachat Free Message Sent!',
            'charged_cost'   => 0.00,
            'is_free_window' => true,
            'new_balance'    => number_format($current_balance, 2)
        ]);
        exit;
    }

    // ==========================================
    // ROUTE B: OFFICIAL WHATSAPP CLOUD API
    // ==========================================
    $stmt_waba = $pdo->prepare("
        SELECT phone_number_id, access_token 
        FROM user_whatsapp_accounts 
        WHERE user_id = ? AND status = 'active' AND phone_number_id IS NOT NULL 
        ORDER BY (access_token IS NOT NULL AND access_token != '') DESC, id DESC 
        LIMIT 1
    ");
    $stmt_waba->execute([$user_id]);
    $waba_row = $stmt_waba->fetch();

    if (!$waba_row || empty($waba_row['phone_number_id']) || empty($waba_row['access_token'])) {
        echo json_encode(['status' => false, 'message' => 'WhatsApp Cloud API is not connected.']);
        exit;
    }

    $phone_number_id = trim($waba_row['phone_number_id']);
    $access_token    = trim($waba_row['access_token']);
    $graph_version   = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';

    $cost_to_charge = 0.00;
    $is_free_window = false;

    // 24-Hour Customer Window Check
    $stmt_24h = $pdo->prepare("
        SELECT id FROM messages 
        WHERE user_id = ? 
          AND (recipient_phone = ? OR recipient_phone LIKE ?) 
          AND direction = 'inbound' 
          AND created_at >= NOW() - INTERVAL 24 HOUR 
        LIMIT 1
    ");
    $stmt_24h->execute([$user_id, $recipient, "%{$last10}%"]);
    if ($stmt_24h->fetch()) {
        $is_free_window = true;
        $cost_to_charge = 0.00;
    } else {
        $cost_to_charge = 0.50; // Deduction margin
    }

    if ($cost_to_charge > 0 && $current_balance < $cost_to_charge) {
        echo json_encode([
            'status'  => false, 
            'message' => 'Insufficient wallet balance. Required: ₹' . number_format($cost_to_charge, 2) . ', Available: ₹' . number_format($current_balance, 2)
        ]);
        exit;
    }

    $meta_api_url = "https://graph.facebook.com/{$graph_version}/{$phone_number_id}/messages";
    $media_url    = trim($_POST['media_url'] ?? '');

    // 1. FREE SESSION (24-Hour Inbound Active) -> Raw text / media
    if ($is_free_window && $mode !== 'template') {
        if (!empty($media_url)) {
            $ext = strtolower(pathinfo(parse_url($media_url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $meta_payload = [
                    "messaging_product" => "whatsapp",
                    "recipient_type"    => "individual",
                    "to"                => $recipient,
                    "type"              => "image",
                    "image"             => ["link" => $media_url, "caption" => $message]
                ];
            } else {
                $meta_payload = [
                    "messaging_product" => "whatsapp",
                    "recipient_type"    => "individual",
                    "to"                => $recipient,
                    "type"              => "document",
                    "document"          => ["link" => $media_url, "caption" => $message, "filename" => basename($media_url)]
                ];
            }
        } else {
            $meta_payload = [
                "messaging_product" => "whatsapp",
                "recipient_type"    => "individual",
                "to"                => $recipient,
                "type"              => "text",
                "text"              => ["preview_url" => true, "body" => $message]
            ];
        }

        if (!empty($reply_to_wamid)) {
            $meta_payload["context"] = ["message_id" => $reply_to_wamid];
        }

        $resRaw = executeMetaCurl($meta_api_url, $meta_payload, $access_token);
    } 
    // 2. OUTSIDE 24 HOURS (Official Template Dispatch - Strict 3 Variables Mapping)
    else {
        $claim_number    = trim($_POST['claim_number'] ?? '');
        $vehicle_number  = trim($_POST['vehicle_number'] ?? '');
        $inspection_link = trim($_POST['inspection_link'] ?? '');

        if (empty($claim_number))    $claim_number    = 'N/A';
        if (empty($vehicle_number))  $vehicle_number  = 'NEW VEHICLE / N/A';
        if (empty($inspection_link)) $inspection_link = 'https://survey.portal/view';

        // Meta Cloud API survey_alert Template Payload with exactly 3 body parameters
        $meta_payload = [
            "messaging_product" => "whatsapp",
            "recipient_type"    => "individual",
            "to"                => $recipient,
            "type"              => "template",
            "template"          => [
                "name"     => "survey_alert",
                "language" => ["code" => "en"],
                "components" => [
                    [
                        "type" => "body",
                        "parameters" => [
                            ["type" => "text", "text" => substr($claim_number, 0, 50)],
                            ["type" => "text", "text" => substr($vehicle_number, 0, 50)],
                            ["type" => "text", "text" => $inspection_link]
                        ]
                    ]
                ]
            ]
        ];

        // Chat table preview formatting
        $message = "📋 [Survey Alert Sent]\nClaim No: {$claim_number}\nVehicle No: {$vehicle_number}\nLink: {$inspection_link}";

        $resRaw = executeMetaCurl($meta_api_url, $meta_payload, $access_token);
    }

    if ($resRaw['code'] === 200 && isset($resRaw['data']['messages'][0]['id'])) {
        $meta_msg_id = $resRaw['data']['messages'][0]['id'];
        
        $new_balance = $current_balance - $cost_to_charge;
        if ($cost_to_charge > 0) {
            $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$new_balance, $user_id]);
        }

        try {
            $stmt_ins = $pdo->prepare("
                INSERT INTO messages (user_id, recipient_phone, message_text, message_body, direction, sent_via, status, wamid, wa_message_id, reply_to_wamid, cost, created_at) 
                VALUES (?, ?, ?, ?, 'outbound', 'whatsapp', 'sent', ?, ?, ?, ?, NOW())
            ");
            $stmt_ins->execute([
                $user_id, 
                $recipient, 
                $message, 
                $message, 
                $meta_msg_id, 
                $meta_msg_id, 
                (!empty($reply_to_wamid) ? $reply_to_wamid : null), 
                $cost_to_charge
            ]);
        } catch(Exception $ex) {}

        echo json_encode([
            'status'         => true,
            'message'        => ($is_free_window && $mode !== 'template') ? 'WhatsApp Message sent successfully!' : 'WhatsApp Survey Template dispatched successfully!',
            'charged_cost'   => $cost_to_charge,
            'is_free_window' => $is_free_window,
            'new_balance'    => number_format($new_balance, 2)
        ]);
        exit;
    } else {
        $meta_err_msg = $resRaw['data']['error']['message'] ?? 'Meta API rejected request';
        echo json_encode([
            'status'  => false,
            'message' => 'Delivery failed: ' . $meta_err_msg
        ]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Send failed: ' . $e->getMessage()]);
}
?>