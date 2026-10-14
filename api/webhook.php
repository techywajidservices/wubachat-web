<?php
// api/webhook.php - Exact Schema Synced Inbound Engine for Wubachat (All Formats Handled)
require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    // Fix: Force MySQL timezone to IST (+05:30) so all inbound messages save with exact correct time
    $pdo->exec("SET time_zone = '+05:30'");
} catch (PDOException $e) {
    http_response_code(500);
    exit("Database Connection Error");
}

$verify_token = defined('META_WEBHOOK_VERIFY_TOKEN') ? META_WEBHOOK_VERIFY_TOKEN : "wubachat_secure_token_2026";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verify_token) {
        http_response_code(200);
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        echo 'Verification token mismatch';
        exit;
    }
}

function downloadMetaMedia($media_id, $access_token, $file_ext = 'jpg') {
    if (empty($media_id) || empty($access_token)) return false;
    $graph_ver = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';
    
    $url = "https://graph.facebook.com/{$graph_ver}/{$media_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);

    $meta_info = json_decode($res, true);
    if (empty($meta_info['url'])) return false;

    $ch2 = curl_init($meta_info['url']);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$access_token}",
        "User-Agent: Wubachat-Media-Engine/1.0"
    ]);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    $binary = curl_exec($ch2);
    curl_close($ch2);

    if (empty($binary)) return false;

    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }

    $filename = 'media_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
    $save_path = $upload_dir . $filename;
    if (file_put_contents($save_path, $binary)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        return $protocol . $_SERVER['HTTP_HOST'] . '/uploads/' . $filename;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = file_get_contents('php://input');
    @file_put_contents(__DIR__ . '/webhook_debug.log', date('[Y-m-d H:i:s] ') . $raw_input . PHP_EOL, FILE_APPEND);

    // Ensure session/connection timezone is strictly IST for every POST execution
    try {
        $pdo->exec("SET time_zone = '+05:30'");
    } catch (Exception $ex) {}

    $data = json_decode($raw_input, true);

    if (!empty($data['entry'][0]['changes'][0]['value'])) {
        $change_value    = $data['entry'][0]['changes'][0]['value'];
        $phone_number_id = $change_value['metadata']['phone_number_id'] ?? '';

        $target_user_id = 1; // Default fallback user (Primary User ID)
        $user_access_token = defined('META_SYSTEM_TOKEN') ? META_SYSTEM_TOKEN : '';

        // Priority Mapping: Select user who owns this WhatsApp number ID for all active users
        if (!empty($phone_number_id)) {
            $stmtA = $pdo->prepare("
                SELECT user_id, access_token 
                FROM user_whatsapp_accounts 
                WHERE phone_number_id = ? AND status = 'active' 
                ORDER BY (access_token IS NOT NULL AND access_token != '') DESC, id DESC 
                LIMIT 1
            ");
            $stmtA->execute([$phone_number_id]);
            $owner = $stmtA->fetch();
            if ($owner) {
                $target_user_id = (int)$owner['user_id'];
                if (!empty($owner['access_token'])) {
                    $user_access_token = $owner['access_token'];
                }
            }
        }

        // Handle Delivery & Read Statuses
        if (!empty($change_value['statuses'][0])) {
            $status_obj = $change_value['statuses'][0];
            $status_val = strtolower($status_obj['status'] ?? '');
            $target_mid = $status_obj['id'] ?? '';

            if (!empty($target_mid) && in_array($status_val, ['queued', 'sent', 'delivered', 'read', 'failed'])) {
                try {
                    $stmtStatus = $pdo->prepare("UPDATE messages SET status = ? WHERE wamid = ? OR wa_message_id = ?");
                    $stmtStatus->execute([$status_val, $target_mid, $target_mid]);
                } catch(Exception $e) {
                    @file_put_contents(__DIR__ . '/webhook_error.log', date('[Y-m-d H:i:s] Status Error: ') . $e->getMessage() . PHP_EOL, FILE_APPEND);
                }
            }
        }

        // Handle Incoming Inbound Messages
        if (!empty($change_value['messages'][0])) {
            $msg_obj     = $change_value['messages'][0];
            $from_raw    = $msg_obj['from'] ?? '';
            $clean_phone = preg_replace('/[^0-9]/', '', $from_raw);
            $wamid       = $msg_obj['id'] ?? '';
            $msg_type    = $msg_obj['type'] ?? 'text';
            $reply_context_wamid = $msg_obj['context']['id'] ?? null;

            if ($msg_type === 'reaction') {
                $reaction_emoji = $msg_obj['reaction']['emoji'] ?? '';
                $target_wamid   = $msg_obj['reaction']['message_id'] ?? '';

                if (!empty($target_wamid)) {
                    try {
                        $stmtR = $pdo->prepare("UPDATE messages SET reaction = ? WHERE (wamid = ? OR wa_message_id = ?)");
                        $stmtR->execute([$reaction_emoji, $target_wamid, $target_wamid]);
                    } catch (Exception $e) {
                        @file_put_contents(__DIR__ . '/webhook_error.log', date('[Y-m-d H:i:s] Reaction Error: ') . $e->getMessage() . PHP_EOL, FILE_APPEND);
                    }
                }
            } else {
                $message_text = '';
                $saved_media_url = null;
                $db_msg_type = 'text';
                
                // 1. Check for Unsupported / Meta Restricted Payloads
                $is_unsupported = ($msg_type === 'unsupported') || !empty($msg_obj['errors']);
                if (!$is_unsupported && isset($msg_obj['unsupported'])) {
                    $is_unsupported = true;
                }

                if ($is_unsupported) {
                    $db_msg_type = 'text';
                    $message_text = "⚠️ [Meta Restricted Action / Unsupported Event]\n\n"
                                  . "📞 English: User tried to make a Voice/Video Call, send View-Once media, or share an unsupported format.\n\n"
                                  . "ℹ️ Hinglish: Samne wale user ne call milayi thi ya aisi photo/video bheji jo Meta API support nahi karta. Meta policy ke mutabiq yeh format yahan show nahi ho sakta.";
                } elseif ($msg_type === 'text') {
                    $message_text = $msg_obj['text']['body'] ?? '';
                    $db_msg_type = 'text';
                } elseif ($msg_type === 'image') {
                    $media_id = $msg_obj['image']['id'] ?? '';
                    $caption  = $msg_obj['image']['caption'] ?? '';
                    $local_url = downloadMetaMedia($media_id, $user_access_token, 'jpg');
                    $saved_media_url = $local_url ?: $media_id;
                    $message_text = "[Image]: " . $saved_media_url . (!empty($caption) ? "\n" . $caption : "");
                    $db_msg_type = 'image';
                } elseif ($msg_type === 'video') {
                    $media_id = $msg_obj['video']['id'] ?? '';
                    $caption  = $msg_obj['video']['caption'] ?? '';
                    $local_url = downloadMetaMedia($media_id, $user_access_token, 'mp4');
                    $saved_media_url = $local_url ?: $media_id;
                    $message_text = "[Video]: " . $saved_media_url . (!empty($caption) ? "\n" . $caption : "");
                    $db_msg_type = 'video';
                } elseif ($msg_type === 'sticker') {
                    $media_id = $msg_obj['sticker']['id'] ?? '';
                    $local_url = downloadMetaMedia($media_id, $user_access_token, 'webp');
                    $saved_media_url = $local_url ?: $media_id;
                    $message_text = "[Sticker]: " . $saved_media_url;
                    $db_msg_type = 'sticker';
                } elseif ($msg_type === 'document') {
                    $media_id = $msg_obj['document']['id'] ?? '';
                    $doc_name = $msg_obj['document']['filename'] ?? 'document.pdf';
                    $ext = pathinfo($doc_name, PATHINFO_EXTENSION) ?: 'pdf';
                    $local_url = downloadMetaMedia($media_id, $user_access_token, $ext);
                    $saved_media_url = $local_url ?: $media_id;
                    $caption  = $msg_obj['document']['caption'] ?? '';
                    $message_text = "[Document]: " . $saved_media_url . (!empty($caption) ? "\n" . $caption : "");
                    $db_msg_type = 'document';
                } elseif ($msg_type === 'audio' || $msg_type === 'voice') {
                    $media_id = $msg_obj[$msg_type]['id'] ?? '';
                    $local_url = downloadMetaMedia($media_id, $user_access_token, 'mp3');
                    $saved_media_url = $local_url ?: $media_id;
                    $message_text = "[Audio]: " . $saved_media_url;
                    $db_msg_type = 'audio';
                } elseif ($msg_type === 'contacts') {
                    $contactsList = $msg_obj['contacts'] ?? [];
                    $cards = [];
                    foreach ($contactsList as $c) {
                        $cName = $c['name']['formatted_name'] ?? ($c['name']['first_name'] ?? 'Contact');
                        $cPhone = !empty($c['phones'][0]['phone']) ? $c['phones'][0]['phone'] : '';
                        $cards[] = "👤 {$cName}: {$cPhone}";
                    }
                    $message_text = "📇 [Shared Contacts]:\n" . implode("\n", $cards);
                    $db_msg_type = 'text';
                } elseif ($msg_type === 'location') {
                    $lat = $msg_obj['location']['latitude'] ?? '';
                    $lng = $msg_obj['location']['longitude'] ?? '';
                    $name = $msg_obj['location']['name'] ?? 'Pinned Location';
                    $address = $msg_obj['location']['address'] ?? '';
                    $message_text = "📍 [Location]: {$name}" . (!empty($address) ? " ({$address})" : "") . "\nhttps://maps.google.com/?q={$lat},{$lng}";
                    $db_msg_type = 'text';
                } elseif ($msg_type === 'interactive') {
                    $interactive_type = $msg_obj['interactive']['type'] ?? '';
                    if ($interactive_type === 'button_reply') {
                        $message_text = "🔘 [Selected]: " . ($msg_obj['interactive']['button_reply']['title'] ?? '');
                    } elseif ($interactive_type === 'list_reply') {
                        $message_text = "📋 [Selected]: " . ($msg_obj['interactive']['list_reply']['title'] ?? '');
                    } else {
                        $message_text = "[Interactive Response]";
                    }
                    $db_msg_type = 'text';
                } elseif ($msg_type === 'button') {
                    $message_text = "🔘 [Button]: " . ($msg_obj['button']['text'] ?? '');
                    $db_msg_type = 'text';
                } else {
                    $encoded = json_encode($msg_obj);
                    if (strpos($encoded, '131051') !== false || strpos($encoded, 'unsupported') !== false) {
                        $message_text = "⚠️ [Meta Restricted Action / Unsupported Event]\n\n"
                                      . "📞 English: User attempted a WhatsApp Call, View-Once media, or sent an unsupported message.\n\n"
                                      . "ℹ️ Hinglish: Samne wale ne call ya unsupported media bheja hai jo Meta policy ki wajah se show nahi kiya ja sakta.";
                    } else {
                        $message_text = "📩 [" . ucfirst($msg_type) . "]: " . substr($encoded, 0, 180);
                    }
                    $db_msg_type = 'text';
                }

                if (!empty($clean_phone) && !empty($message_text)) {
                    try {
                        $stmtCheckContact = $pdo->prepare("SELECT id FROM contacts WHERE user_id = ? AND (phone_number = ? OR RIGHT(phone_number, 10) = ?) LIMIT 1");
                        $stmtCheckContact->execute([$target_user_id, $clean_phone, substr($clean_phone, -10)]);
                        $contact_row = $stmtCheckContact->fetch();
                        $contact_id = $contact_row ? (int)$contact_row['id'] : null;

                        if (!$contact_id) {
                            $stmtInsContact = $pdo->prepare("INSERT INTO contacts (user_id, contact_name, phone_number) VALUES (?, ?, ?)");
                            $stmtInsContact->execute([$target_user_id, '+' . $clean_phone, $clean_phone]);
                            $contact_id = (int)$pdo->lastInsertId();
                        }

                        $stmtIns = $pdo->prepare("
                            INSERT INTO messages (
                                user_id, contact_id, recipient_phone, message_text, message_body, 
                                wa_message_id, direction, sent_via, message_type, media_url, 
                                status, wamid, reply_to_wamid, cost, created_at
                            ) VALUES (
                                ?, ?, ?, ?, ?, 
                                ?, 'inbound', 'whatsapp', ?, ?, 
                                'unread', ?, ?, 0.00, NOW()
                            )
                        ");
                        $stmtIns->execute([
                            $target_user_id,
                            $contact_id,
                            $clean_phone,
                            $message_text,
                            $message_text,
                            $wamid,
                            $db_msg_type,
                            $saved_media_url,
                            $wamid,
                            $reply_context_wamid
                        ]);

                    } catch (Exception $e) {
                        @file_put_contents(__DIR__ . '/webhook_error.log', date('[Y-m-d H:i:s] DB Insert Error: ') . $e->getMessage() . PHP_EOL, FILE_APPEND);
                    }
                }
            }
        }
    }

    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit;
}
?>