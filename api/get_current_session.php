<?php
// api/get_current_session.php - Bridge for Extension to identify logged in user
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://sage.paramountservices.co.in');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode(['status' => false, 'message' => 'No active session']);
    exit;
}

require_once __DIR__ . '/../config.php';

$stmt = $pdo->prepare("SELECT phone, mobile FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user_id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

$phone = $u['phone'] ?? ($u['mobile'] ?? '');

echo json_encode([
    'status'  => true,
    'user_id' => $user_id,
    'phone'   => $phone
]);