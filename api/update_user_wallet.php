<?php
// api/update_user_wallet.php - Admin Wallet Balance Modifier
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Check if admin is logged in (apne session ke mutabiq admin check lagayein)
    if (!isset($_SESSION['user_id'])) { // Aap yahan admin session check variable daal sakte hain
        echo json_encode(['status' => false, 'message' => 'Unauthorized access.']);
        exit;
    }

    require_once __DIR__ . '/../config.php';

    if (!isset($pdo)) {
        throw new Exception("Database connection object not found.");
    }

    $user_id     = (int)($_POST['user_id'] ?? 0);
    $new_balance = floatval($_POST['new_balance'] ?? -1);

    if ($user_id <= 0 || $new_balance < 0) {
        echo json_encode(['status' => false, 'message' => 'Invalid user ID or wallet amount.']);
        exit;
    }

    // 2. Update user wallet balance directly in database
    $stmt = $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
    $stmt->execute([$new_balance, $user_id]);

    // 3. Optional: Log this admin action in wallet_transactions for audit history
    $stmt_log = $pdo->prepare("
        INSERT INTO wallet_transactions (user_id, amount, type, status, created_at) 
        VALUES (?, ?, 'admin_adjustment', 'approved', NOW())
    ");
    $stmt_log->execute([$user_id, $new_balance]);

    // Redirect back to admin dashboard with success
    if (!headers_sent()) {
        header("Location: ../admin/dashboard.php?msg=Wallet+updated+successfully");
        exit;
    }

    echo json_encode(['status' => true, 'message' => 'Wallet balance updated successfully!']);

} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>