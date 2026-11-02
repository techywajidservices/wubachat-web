<?php
// api/check_user.php - Real-time Discovery for Wubachat vs WhatsApp Users
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$raw_phone = $_GET['phone'] ?? ($_POST['phone'] ?? '');
$clean_phone = preg_replace('/[^0-9]/', '', $raw_phone);

if (empty($clean_phone)) {
    echo json_encode(['status' => false, 'is_wubachat_user' => false, 'message' => 'Invalid phone number']);
    exit;
}

$last10 = (strlen($clean_phone) >= 10) ? substr($clean_phone, -10) : $clean_phone;

try {
    // 1. Check if recipient exists in Wubachat users database
    $stmt = $pdo->prepare("
        SELECT id, name, full_name, avatar_url, bio 
        FROM users 
        WHERE phone = ? 
           OR phone = ? 
           OR phone = ? 
           OR phone LIKE ? 
        LIMIT 1
    ");
    $stmt->execute([
        $clean_phone,
        $last10,
        '91' . $last10,
        '%' . $last10
    ]);
    $matched_user = $stmt->fetch();

    $is_wubachat = !empty($matched_user);

    // 2. Cache result into contacts table for active session user
    $current_uid = (int)$_SESSION['user_id'];
    $pdo->prepare("
        UPDATE contacts 
        SET is_wubachat_user = ? 
        WHERE user_id = ? 
          AND (phone_number = ? OR phone_number LIKE ?)
    ")->execute([$is_wubachat ? 1 : 0, $current_uid, $clean_phone, '%' . $last10]);

    echo json_encode([
        'status'           => true,
        'is_wubachat_user' => $is_wubachat,
        'user_details'     => $is_wubachat ? [
            'name'   => $matched_user['full_name'] ?: ($matched_user['name'] ?: 'Wubachat User'),
            'avatar' => $matched_user['avatar_url'] ?? null,
            'bio'    => $matched_user['bio'] ?? ''
        ] : null
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'is_wubachat_user' => false, 'error' => $e->getMessage()]);
}
?>