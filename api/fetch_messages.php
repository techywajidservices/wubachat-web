<?php
// api/fetch_messages.php - Schema-Aligned Incremental Delta Sync Message Fetcher
ini_set('display_errors', 0);
error_reporting(0);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    session_write_close();
    echo json_encode(['status' => false, 'messages' => [], 'error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
session_write_close(); // Session unlock: background polling aur button click ka lag khatam

require_once __DIR__ . '/../config.php';

$raw_phone = $_GET['phone'] ?? '';
$phone     = preg_replace('/[^0-9]/', '', $raw_phone);
$after_id  = (int)($_GET['after_id'] ?? ($_GET['last_id'] ?? 0));
$action    = $_GET['action'] ?? '';

// MULTI-CHAT BUNDLE: Fast Delta Sync (Sirf wahi messages uthayega jo browser ke paas nahi hain)
if ($action === 'bundle_all_recent') {
    $since_id = (int)($_GET['since_id'] ?? 0);

    if ($since_id > 0) {
        // Agar browser ne highest saved ID di hai, toh sirf uske baad ke NAYE messages mangwao
        $stmtBundle = $pdo->prepare("
            SELECT id, 
                   COALESCE(recipient_phone, '') AS recipient, 
                   COALESCE(message_text, '') AS message_text, 
                   COALESCE(media_url, '') AS media_url, 
                   COALESCE(message_type, 'text') AS message_type, 
                   COALESCE(direction, 'inbound') AS direction, 
                   COALESCE(status, 'sent') AS status, 
                   COALESCE(reaction, '') AS reaction, 
                   COALESCE(wamid, '') AS wamid, 
                   COALESCE(reply_to_wamid, '') AS reply_to_wamid, 
                   COALESCE(sent_via, 'whatsapp') AS sent_via, 
                   COALESCE(is_deleted_for_everyone, 0) AS is_deleted_for_everyone, 
                   COALESCE(deleted_by_users, '') AS deleted_by_users,
                   COALESCE(created_at, NOW()) AS created_at 
            FROM messages 
            WHERE user_id = ? 
              AND id > ?
              AND is_deleted_for_everyone = 0
            ORDER BY id ASC
            LIMIT 100
        ");
        $stmtBundle->execute([$user_id, $since_id]);
    } else {
        // Initial setup: Top recent messages uthao
        $stmtBundle = $pdo->prepare("
            SELECT id, 
                   COALESCE(recipient_phone, '') AS recipient, 
                   COALESCE(message_text, '') AS message_text, 
                   COALESCE(media_url, '') AS media_url, 
                   COALESCE(message_type, 'text') AS message_type, 
                   COALESCE(direction, 'inbound') AS direction, 
                   COALESCE(status, 'sent') AS status, 
                   COALESCE(reaction, '') AS reaction, 
                   COALESCE(wamid, '') AS wamid, 
                   COALESCE(reply_to_wamid, '') AS reply_to_wamid, 
                   COALESCE(sent_via, 'whatsapp') AS sent_via, 
                   COALESCE(is_deleted_for_everyone, 0) AS is_deleted_for_everyone, 
                   COALESCE(deleted_by_users, '') AS deleted_by_users,
                   COALESCE(created_at, NOW()) AS created_at 
            FROM messages 
            WHERE user_id = ? 
              AND is_deleted_for_everyone = 0
            ORDER BY id DESC 
            LIMIT 60
        ");
        $stmtBundle->execute([$user_id]);
    }

    $allMsgs = $stmtBundle->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($allMsgs as $m) {
        // Deleted by user check
        if (!empty($m['deleted_by_users'])) {
            $delArr = json_decode($m['deleted_by_users'], true);
            if (is_array($delArr) && in_array($user_id, $delArr)) {
                continue;
            }
        }

        $p = substr(preg_replace('/[^0-9]/', '', $m['recipient']), -10);
        if (!$p) continue;
        if (!isset($grouped[$p])) {
            $grouped[$p] = [];
        }
        $grouped[$p][] = $m;
    }

    // Chronological order format
    if ($since_id <= 0) {
        foreach ($grouped as $ph => $arr) {
            $grouped[$ph] = array_reverse($arr);
        }
    }

    echo json_encode(['status' => true, 'chats' => $grouped]);
    exit;
}

// 1. Media Download Stream
if ($action === 'download_stream') {
    $media_url = trim($_GET['url'] ?? '');
    if (empty($media_url)) exit("Invalid URL");
    $filename = basename(parse_url($media_url, PHP_URL_PATH)) ?: ('file_' . time());
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $ch = curl_init($media_url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    exit;
}

if (empty($phone)) {
    echo json_encode(['status' => true, 'messages' => [], 'latest_id' => 0]);
    exit;
}

$last10 = substr($phone, -10);
$phone_clean = '91' . $last10;

try {
    // 2. Contact Directory Profile Lookup
    $stmtC = $pdo->prepare("
        SELECT COALESCE(contact_name, name, '') AS contact_name 
        FROM contacts 
        WHERE user_id = ? AND (phone_number = ? OR phone_number = ? OR phone_number = ?)
        LIMIT 1
    ");
    $stmtC->execute([$user_id, $last10, $phone_clean, $phone]);
    $contactData = $stmtC->fetch();

    $contactInfo = [
        'name'   => !empty($contactData['contact_name']) ? $contactData['contact_name'] : ('+' . $phone),
        'avatar' => '',
        'bio'    => 'Hey there! I am using WhatsApp.'
    ];

    // 3. Single Chat Incremental Fetch (Delta Sync)
    if ($after_id > 0) {
        $stmt = $pdo->prepare("
            SELECT id, 
                   COALESCE(recipient_phone, '') AS recipient, 
                   COALESCE(message_text, '') AS message_text, 
                   COALESCE(media_url, '') AS media_url, 
                   COALESCE(message_type, 'text') AS message_type, 
                   COALESCE(direction, 'inbound') AS direction, 
                   COALESCE(status, 'sent') AS status, 
                   COALESCE(reaction, '') AS reaction, 
                   COALESCE(wamid, '') AS wamid, 
                   COALESCE(reply_to_wamid, '') AS reply_to_wamid, 
                   COALESCE(sent_via, 'whatsapp') AS sent_via, 
                   COALESCE(is_deleted_for_everyone, 0) AS is_deleted_for_everyone, 
                   COALESCE(deleted_by_users, '') AS deleted_by_users,
                   COALESCE(created_at, NOW()) AS created_at 
            FROM messages 
            WHERE user_id = ? 
              AND (recipient_phone = ? OR recipient_phone = ? OR recipient_phone = ?)
              AND id > ?
            ORDER BY id ASC
        ");
        $stmt->execute([$user_id, $last10, $phone_clean, $phone, $after_id]);
        $raw_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, 
                   COALESCE(recipient_phone, '') AS recipient, 
                   COALESCE(message_text, '') AS message_text, 
                   COALESCE(media_url, '') AS media_url, 
                   COALESCE(message_type, 'text') AS message_type, 
                   COALESCE(direction, 'inbound') AS direction, 
                   COALESCE(status, 'sent') AS status, 
                   COALESCE(reaction, '') AS reaction, 
                   COALESCE(wamid, '') AS wamid, 
                   COALESCE(reply_to_wamid, '') AS reply_to_wamid, 
                   COALESCE(sent_via, 'whatsapp') AS sent_via, 
                   COALESCE(is_deleted_for_everyone, 0) AS is_deleted_for_everyone, 
                   COALESCE(deleted_by_users, '') AS deleted_by_users,
                   COALESCE(created_at, NOW()) AS created_at 
            FROM messages 
            WHERE user_id = ? 
              AND (recipient_phone = ? OR recipient_phone = ? OR recipient_phone = ?)
            ORDER BY id DESC 
            LIMIT 40
        ");
        $stmt->execute([$user_id, $last10, $phone_clean, $phone]);
        $raw_messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    $messages = [];
    foreach ($raw_messages as $m) {
        if (!empty($m['deleted_by_users'])) {
            $delArr = json_decode($m['deleted_by_users'], true);
            if (is_array($delArr) && in_array($user_id, $delArr)) {
                continue;
            }
        }

        if ((int)$m['is_deleted_for_everyone'] === 1) {
            $m['message_text'] = '🚫 This message was deleted';
        }
        $messages[] = $m;
    }

    // 4. Inbound unread status update
    if (!empty($messages)) {
        $pdo->prepare("UPDATE messages SET status = 'read' WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone = ? OR recipient_phone = ?) AND direction = 'inbound' AND status = 'unread'")
            ->execute([$user_id, $last10, $phone_clean, $phone]);
    }

    $latest_id = !empty($messages) ? end($messages)['id'] : $after_id;

    echo json_encode([
        'status'    => true, 
        'messages'  => $messages,
        'contact'   => $contactInfo,
        'latest_id' => $latest_id
    ]);
} catch(Exception $e) {
    echo json_encode(['status' => false, 'messages' => [], 'contact' => null, 'error' => $e->getMessage()]);
}