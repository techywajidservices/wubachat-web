<?php
// api/test_local_media.php - Test if Meta can download local uploads from this server
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$stmt = $pdo->query("SELECT user_id, phone_number_id, access_token FROM user_whatsapp_accounts WHERE status = 'active' AND access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 1");
$waba = $stmt->fetch();

if (!$waba) {
    echo json_encode(['status' => false, 'error' => 'No active WhatsApp account found.']);
    exit;
}

$phone_number_id = trim($waba['phone_number_id']);
$access_token    = trim($waba['access_token']);
$test_recipient  = '919871529258';

// Find the latest uploaded file in uploads folder
$upload_dir = __DIR__ . '/../uploads/';
$files = glob($upload_dir . '*.*');
if (empty($files)) {
    echo json_encode(['status' => false, 'error' => 'No files found in uploads/ directory to test. Please upload a photo first via dashboard.']);
    exit;
}

// Get the most recent file
rsort($files);
$latest_file = $files[0];
$filename = basename($latest_file);

$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$local_media_url = $protocol . $_SERVER['HTTP_HOST'] . '/uploads/' . $filename;

$payload = [
    'messaging_product' => 'whatsapp',
    'recipient_type'    => 'individual',
    'to'                => $test_recipient,
    'type'              => 'image',
    'image'             => [
        'link' => $local_media_url,
        'caption' => 'Local Upload Test from Wubachat'
    ]
];

$ch = curl_init("https://graph.facebook.com/v20.0/{$phone_number_id}/messages");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $access_token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
    'tested_local_url' => $local_media_url,
    'http_status_code' => $http_code,
    'meta_response'    => json_decode($res, true)
], JSON_PRETTY_PRINT);
?>