<?php
// api/bulk_send.php - Multi-Media & Broadcast Gateway with wubachat_general_alert Template
session_start();
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id       = $_SESSION['user_id'];
$campaign_name = trim($_POST['campaign_name'] ?? 'Broadcast Campaign');
$numbers_raw   = $_POST['numbers'] ?? '';
$message       = trim($_POST['message'] ?? '');

// 1. Sanitize & extract numbers with 91 prefix
$lines = preg_split('/[\r\n,]+/', $numbers_raw);
$valid_numbers = [];
foreach ($lines as $line) {
    $num = preg_replace('/[^0-9]/', '', $line);
    if (strlen($num) >= 10) {
        $ten_digit = substr($num, -10);
        $valid_numbers[] = '91' . $ten_digit;
    }
}
$unique_recipients = array_values(array_unique($valid_numbers));
$total_count = count($unique_recipients);

if ($total_count === 0) {
    echo json_encode(['status' => false, 'message' => 'Please provide at least one valid 10-digit mobile number.']);
    exit;
}

if ($total_count > 1000) {
    echo json_encode(['status' => false, 'message' => 'Maximum limit is 1,000 numbers per broadcast.']);
    exit;
}

// 2. Handle Attached Files & Pasted Screenshots
$uploaded_media_urls = [];
$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

if (!empty($_FILES['attachments']['name'][0])) {
    $total_files = count($_FILES['attachments']['name']);
    for ($i = 0; $i < $total_files; $i++) {
        if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['attachments']['tmp_name'][$i];
            $orig_name = basename($_FILES['attachments']['name'][$i]);
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $new_name = 'bulk_' . time() . '_' . uniqid() . '.' . $ext;
            $target_path = $upload_dir . $new_name;

            if (move_uploaded_file($tmp_name, $target_path)) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $base_url = $protocol . $_SERVER['HTTP_HOST'];
                $media_url = $base_url . '/uploads/' . $new_name;
                
                // Classify Type
                $type_prefix = '[Document]: ';
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    $type_prefix = '[Image]: ';
                } elseif (in_array($ext, ['mp3', 'ogg', 'wav', 'webm', 'm4a', 'aac'])) {
                    $type_prefix = '[Audio]: ';
                } elseif (in_array($ext, ['mp4', 'mkv', 'mov', 'avi'])) {
                    $type_prefix = '[Video]: ';
                }
                
                $uploaded_media_urls[] = [
                    'url' => $media_url,
                    'type_prefix' => $type_prefix,
                    'ext' => $ext,
                    'is_image' => in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])
                ];
            }
        }
    }
}

if (empty($message) && empty($uploaded_media_urls)) {
    echo json_encode(['status' => false, 'message' => 'Please enter broadcast text or attach at least one file/screenshot.']);
    exit;
}

// 3. Rate calculation: ₹0.99 per contact for Marketing Bulk Blast
$rate_per_contact = 0.99;
$total_cost = $total_count * $rate_per_contact;

try {
    // 4. Fetch User's Verified Meta Credentials
    $stmt_waba = $pdo->prepare("SELECT phone_number_id, access_token, status FROM user_whatsapp_accounts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $stmt_waba->execute([$user_id]);
    $waba_row = $stmt_waba->fetch();

    $phone_number_id = $waba_row['phone_number_id'] ?? (defined('META_PHONE_NUMBER_ID') ? META_PHONE_NUMBER_ID : '');
    $access_token    = $waba_row['access_token'] ?? (defined('META_SYSTEM_TOKEN') ? META_SYSTEM_TOKEN : '');
    $graph_version   = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';

    // 5. Check Wallet Balance
    $stmt_user = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch();
    $current_balance = (float)($user['wallet_balance'] ?? 0.00);

    if ($current_balance < $total_cost) {
        echo json_encode([
            'status'  => false,
            'message' => "Insufficient wallet balance. Required: ₹" . number_format($total_cost, 2) . " for $total_count contacts. Current balance: ₹" . number_format($current_balance, 2) . ". Please recharge."
        ]);
        exit;
    }

    // 6. Deduct Wallet Balance
    $new_balance = $current_balance - $total_cost;
    $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$new_balance, $user_id]);

    // 7. Broadcast Messages to DB & Meta API
    $stmt_msg = $pdo->prepare("INSERT INTO messages (user_id, recipient_phone, message_text, direction, status, cost, created_at) VALUES (?, ?, ?, 'outbound', 'sent', ?, NOW())");

    $meta_api_url = "https://graph.facebook.com/{$graph_version}/{$phone_number_id}/messages";
    $has_meta = (!empty($phone_number_id) && !empty($access_token));

    $template_name = defined('META_DEFAULT_TEMPLATE') ? META_DEFAULT_TEMPLATE : 'wubachat_general_alert';
    $template_lang = defined('META_DEFAULT_TEMPLATE_LANG') ? META_DEFAULT_TEMPLATE_LANG : 'en';

    foreach ($unique_recipients as $phone) {
        $last10 = substr($phone, -10);

        // Check 24-Hour Inbound Window for this specific recipient
        $stmt_24h = $pdo->prepare("
            SELECT id FROM messages 
            WHERE user_id = ? 
              AND (recipient_phone = ? OR recipient_phone LIKE ?) 
              AND direction = 'inbound' 
              AND created_at >= NOW() - INTERVAL 24 HOUR 
            LIMIT 1
        ");
        $stmt_24h->execute([$user_id, $phone, "%{$last10}%"]);
        $is_free_window = (bool)$stmt_24h->fetch();

        // A. Send attached media items
        foreach ($uploaded_media_urls as $media_item) {
            $formatted_media_text = $media_item['type_prefix'] . $media_item['url'];
            $stmt_msg->execute([$user_id, $phone, $formatted_media_text, 0.00]);

            if ($has_meta) {
                $media_type = $media_item['is_image'] ? 'image' : 'document';
                $meta_media_payload = [
                    "messaging_product" => "whatsapp",
                    "recipient_type"    => "individual",
                    "to"                => $phone,
                    "type"              => $media_type,
                    $media_type => [
                        "link" => $media_item['url']
                    ]
                ];
                
                $ch = curl_init($meta_api_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($meta_media_payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                @curl_exec($ch);
                curl_close($ch);
            }
        }

        // B. Send Text Content (Auto-routing via Template if outside 24-hr session)
        if (!empty($message)) {
            $stmt_msg->execute([$user_id, $phone, $message, $rate_per_contact]);

            if ($has_meta) {
                if ($is_free_window) {
                    // Raw text inside active session
                    $meta_text_payload = [
                        "messaging_product" => "whatsapp",
                        "recipient_type"    => "individual",
                        "to"                => $phone,
                        "type"              => "text",
                        "text"              => ["body" => $message]
                    ];
                } else {
                    // Auto-wrap into wubachat_general_alert template outside 24-hr session
                    $meta_text_payload = [
                        "messaging_product" => "whatsapp",
                        "recipient_type"    => "individual",
                        "to"                => $phone,
                        "type"              => "template",
                        "template"          => [
                            "name"     => $template_name,
                            "language" => ["code" => $template_lang],
                            "components" => [
                                [
                                    "type" => "body",
                                    "parameters" => [
                                        [
                                            "type" => "text", 
                                            "text" => $message
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ];
                }

                $ch = curl_init($meta_api_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($meta_text_payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                @curl_exec($ch);
                curl_close($ch);
            }
        }
    }

    // 8. Activity Audit Log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $media_count_note = count($uploaded_media_urls) > 0 ? " with " . count($uploaded_media_urls) . " media attachments" : "";
    $pdo->prepare("INSERT INTO activity_logs (user_id, phone, activity_type, description, ip_address) VALUES (?, 'BULK', 'BULK_BLAST', ?, ?)")
        ->execute([$user_id, "Launched broadcast '$campaign_name' to $total_count recipients$media_count_note (Charged ₹" . number_format($total_cost, 2) . ")", $ip]);

    echo json_encode([
        'status'      => true,
        'message'     => "Broadcast '$campaign_name' launched to $total_count contacts successfully!",
        'new_balance' => number_format($new_balance, 2)
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Bulk blast failed: ' . $e->getMessage()]);
}
?>