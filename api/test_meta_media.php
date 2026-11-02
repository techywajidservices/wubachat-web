<?php
// api/test_meta_media.php - Direct Meta API Media Delivery Diagnostic Tool
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Fetch the most recent active WhatsApp account from database directly
$stmt = $pdo->query("SELECT user_id, phone_number_id, access_token FROM user_whatsapp_accounts WHERE status = 'active' AND access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 1");
$waba = $stmt->fetch();

if (!$waba) {
    echo json_encode(['status' => false, 'error' => 'No active WhatsApp account found in user_whatsapp_accounts table.']);
    exit;
}

$phone_number_id = trim($waba['phone_number_id']);
$access_token    = trim($waba['access_token']);
$test_recipient  = '919871529258'; // Aap apna test number yahan rakh sakte hain

// Test public image URL
$test_media_url  = 'https://raw.githubusercontent.com/github/explore/80688e429a7d4ef2fca1e82350fe8e3517d3494d/topics/php/php.png';

$payload = [
    'messaging_product' => 'whatsapp',
    'recipient_type'    => 'individual',
    'to'                => $test_recipient,
    'type'              => 'image',
    'image'             => [
        'link' => $test_media_url,
        'caption' => 'Diagnostic Test Image from Wubachat'
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
    'active_user_id'   => $waba['user_id'],
    'phone_number_id'  => $phone_number_id,
    'http_status_code' => $http_code,
    'meta_response'    => json_decode($res, true),
    'raw_output'       => $res
], JSON_PRETTY_PRINT);
?>