<?php
// api/upload_media.php - Zero-Storage Hostinger Protection, Auto Mime Engine & Meta Media Dispatcher
ini_set('display_errors', 0);
error_reporting(0);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id         = (int)$_SESSION['user_id'];
$raw_recipient   = trim($_POST['recipient'] ?? '');
$raw_media_type  = strtolower(trim($_POST['media_type'] ?? ''));
$media_url_param = trim($_POST['media_url'] ?? '');
$sent_via        = strtolower(trim($_POST['sent_via'] ?? 'whatsapp'));
$reply_to_wamid  = trim($_POST['reply_to_wamid'] ?? '');
$caption_text    = trim($_POST['message'] ?? '');

if (empty($raw_recipient)) {
    echo json_encode(['status' => false, 'message' => 'Recipient mobile number is required']);
    exit;
}

$clean_phone = preg_replace('/[^0-9]/', '', $raw_recipient);
if (strlen($clean_phone) === 10) {
    $clean_phone = '91' . $clean_phone;
} elseif (strlen($clean_phone) === 11 && strpos($clean_phone, '0') === 0) {
    $clean_phone = '91' . substr($clean_phone, 1);
}

$last10 = substr($clean_phone, -10);

try {
    // 0. Block Guard Verification
    $stmtBlock = $pdo->prepare("SELECT id FROM blocked_contacts WHERE user_id = ? AND (phone_number = ? OR RIGHT(phone_number, 10) = ?) LIMIT 1");
    $stmtBlock->execute([$user_id, $clean_phone, $last10]);
    if ($stmtBlock->fetch()) {
        echo json_encode(['status' => false, 'message' => 'You have blocked this contact. Unblock to send media.']);
        exit;
    }

    $media_url = '';
    $original_name = '';
    $ext = '';
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }

    // Case A: Forwarding or reusing existing media
    if (!empty($media_url_param)) {
        $media_url = $media_url_param;
        $original_name = basename($media_url);
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    } 
    // Case B: Direct Upload
    else if (!empty($_FILES['file'])) {
        $file = $_FILES['file'];
        $original_name = $file['name'];
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        // Deep MIME Type Detection for Excel, PDF, Word etc.
        $detected_mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected_mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $detected_mime = mime_content_type($file['tmp_name']);
        }

        if (empty($ext) || $ext === 'bin') {
            if (strpos($detected_mime, 'spreadsheet') !== false || strpos($detected_mime, 'excel') !== false || strpos($detected_mime, 'openxmlformats-officedocument.spreadsheetml') !== false) {
                $ext = 'xlsx';
            } elseif (strpos($detected_mime, 'pdf') !== false) {
                $ext = 'pdf';
            } elseif (strpos($detected_mime, 'word') !== false || strpos($detected_mime, 'openxmlformats-officedocument.wordprocessingml') !== false) {
                $ext = 'docx';
            } elseif (strpos($detected_mime, 'image/jpeg') !== false) {
                $ext = 'jpg';
            } elseif (strpos($detected_mime, 'image/png') !== false) {
                $ext = 'png';
            } elseif (strpos($detected_mime, 'audio') !== false) {
                $ext = 'mp3';
            } else {
                $ext = 'pdf';
            }
        }

        $filename    = 'media_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $target_file = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            $protocol  = "https://";
            $media_url = $protocol . $_SERVER['HTTP_HOST'] . '/uploads/' . $filename;
        } else {
            echo json_encode(['status' => false, 'message' => 'Upload failed. Check directory permissions.']);
            exit;
        }
    } else {
        echo json_encode(['status' => false, 'message' => 'No media file provided']);
        exit;
    }

    // Precise Category Determination
    $doc_exts   = ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'zip', 'rar'];
    $audio_exts = ['mp3', 'ogg', 'wav', 'm4a', 'aac'];
    $video_exts = ['mp4', '3gp', 'mov', 'avi'];

    if (in_array($ext, $doc_exts) || $raw_media_type === 'document' || $raw_media_type === 'pdf' || $raw_media_type === 'excel') {
        $final_media_type = 'document';
        $meta_type        = 'document';
        $prefix           = '[Document]: ';
    } elseif (in_array($ext, $audio_exts) || $raw_media_type === 'audio') {
        $final_media_type = 'audio';
        $meta_type        = 'audio';
        $prefix           = '[Audio]: ';
    } elseif (in_array($ext, $video_exts) || $raw_media_type === 'video') {
        $final_media_type = 'video';
        $meta_type        = 'video';
        $prefix           = '[Video]: ';
    } else {
        $final_media_type = 'image';
        $meta_type        = 'image';
        $prefix           = '[Image]: ';
    }

    $msg_body = $prefix . $media_url . (!empty($caption_text) ? "\n" . $caption_text : "");

    $stmt_sender = $pdo->prepare("SELECT phone, wallet_balance FROM users WHERE id = ? LIMIT 1");
    $stmt_sender->execute([$user_id]);
    $sender_user = $stmt_sender->fetch();
    $sender_phone = preg_replace('/[^0-9]/', '', $sender_user['phone'] ?? '');
    $current_balance = (float)($sender_user['wallet_balance'] ?? 0.00);

    // Check if recipient exists on internal Wubachat network
    $stmt_rcpt_user = $pdo->prepare("SELECT id FROM users WHERE phone = ? OR RIGHT(phone, 10) = ? LIMIT 1");
    $stmt_rcpt_user->execute([$clean_phone, $last10]);
    $rcpt_account = $stmt_rcpt_user->fetch();

    // ==========================================
    // ROUTE A: WUBACHAT INTERNAL (FREE / ₹0)
    // ==========================================
    if ($sent_via === 'wubachat' && $rcpt_account) {
        $internal_msg_id = 'wuba_media_' . time() . '_' . rand(1000, 9999);

        $stmt = $pdo->prepare("
            INSERT INTO messages (user_id, recipient_phone, message_text, message_body, direction, sent_via, status, wamid, wa_message_id, reply_to_wamid, cost_deducted, media_url, message_type, created_at) 
            VALUES (?, ?, ?, ?, 'outbound', 'wubachat', 'delivered', ?, ?, ?, 0.00, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            $clean_phone,
            $msg_body,
            $msg_body,
            $internal_msg_id,
            $internal_msg_id,
            (!empty($reply_to_wamid) ? $reply_to_wamid : null),
            $media_url,
            $final_media_type
        ]);

        $rcpt_uid = (int)$rcpt_account['id'];
        $stmt_rcpt_inbox = $pdo->prepare("
            INSERT INTO messages (user_id, recipient_phone, message_text, message_body, direction, sent_via, status, wamid, wa_message_id, reply_to_wamid, cost_deducted, media_url, message_type, created_at) 
            VALUES (?, ?, ?, ?, 'inbound', 'wubachat', 'unread', ?, ?, ?, 0.00, ?, ?, NOW())
        ");
        $stmt_rcpt_inbox->execute([
            $rcpt_uid,
            $sender_phone,
            $msg_body,
            $msg_body,
            $internal_msg_id,
            $internal_msg_id,
            (!empty($reply_to_wamid) ? $reply_to_wamid : null),
            $media_url,
            $final_media_type
        ]);

        echo json_encode([
            'status'     => true,
            'message'    => 'Media sent via Wubachat Internal!',
            'media_url'  => $media_url,
            'media_type' => $final_media_type,
            'cost'       => 0.00
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
    $waba = $stmt_waba->fetch();

    $phone_number_id = trim($waba['phone_number_id'] ?? '');
    $access_token    = trim($waba['access_token'] ?? '');
    $graph_ver       = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';

    if (empty($phone_number_id) || empty($access_token)) {
        echo json_encode(['status' => false, 'message' => 'WhatsApp Cloud API is not connected.']);
        exit;
    }

    // 24-hr Free Window Check
    $cost_to_charge = 0.50;
    $stmt_24h = $pdo->prepare("
        SELECT id FROM messages 
        WHERE user_id = ? 
          AND (recipient_phone = ? OR RIGHT(recipient_phone, 10) = ?) 
          AND direction = 'inbound' 
          AND created_at >= NOW() - INTERVAL 24 HOUR 
        LIMIT 1
    ");
    $stmt_24h->execute([$user_id, $clean_phone, $last10]);
    if ($stmt_24h->fetch()) {
        $cost_to_charge = 0.00;
    }

    if ($cost_to_charge > 0 && $current_balance < $cost_to_charge) {
        echo json_encode(['status' => false, 'message' => 'Insufficient wallet balance for WhatsApp media. Required: ₹0.50']);
        exit;
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $clean_phone,
        'type'              => $meta_type,
        $meta_type          => [
            'link' => $media_url
        ]
    ];

    if ($meta_type === 'document') {
        $clean_disp_name = !empty($original_name) && strtolower($original_name) !== 'blob' ? basename($original_name) : basename($media_url);
        if (strtolower(pathinfo($clean_disp_name, PATHINFO_EXTENSION)) !== $ext) {
            $clean_disp_name = pathinfo($clean_disp_name, PATHINFO_FILENAME) . '.' . $ext;
        }
        $payload['document']['filename'] = $clean_disp_name;
        if (!empty($caption_text)) {
            $payload['document']['caption'] = $caption_text;
        }
    } elseif ($meta_type === 'image' || $meta_type === 'video') {
        if (!empty($caption_text)) {
            $payload[$meta_type]['caption'] = $caption_text;
        }
    }

    if (!empty($reply_to_wamid)) {
        $payload['context'] = ['message_id' => $reply_to_wamid];
    }

    $ch = curl_init("https://graph.facebook.com/{$graph_ver}/{$phone_number_id}/messages");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resData = json_decode($res, true);

    if ($http_code !== 200 || !isset($resData['messages'][0]['id'])) {
        $error_message = $resData['error']['message'] ?? 'Meta API rejected media delivery';
        echo json_encode(['status' => false, 'message' => 'Meta Error: ' . $error_message]);
        exit;
    }

    $meta_msg_id = $resData['messages'][0]['id'];

    $new_balance = $current_balance - $cost_to_charge;
    if ($cost_to_charge > 0) {
        $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$new_balance, $user_id]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO messages (user_id, recipient_phone, message_text, message_body, direction, sent_via, status, wamid, wa_message_id, reply_to_wamid, cost_deducted, media_url, message_type, created_at) 
        VALUES (?, ?, ?, ?, 'outbound', 'whatsapp', 'sent', ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $user_id,
        $clean_phone,
        $msg_body,
        $msg_body,
        $meta_msg_id,
        $meta_msg_id,
        (!empty($reply_to_wamid) ? $reply_to_wamid : null),
        $cost_to_charge,
        $media_url,
        $final_media_type
    ]);

    echo json_encode([
        'status'      => true,
        'message'     => 'Media sent via WhatsApp API',
        'media_url'   => $media_url,
        'media_type'  => $final_media_type,
        'new_balance' => number_format($new_balance, 2)
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Upload error: ' . $e->getMessage()]);
}
?>