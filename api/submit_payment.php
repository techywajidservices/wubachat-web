<?php
// api/submit_payment.php - Inbound Payment Request & 1-Click Admin Email Dispatcher
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id = (int)$_SESSION['user_id'];
$amount  = (float)($_POST['amount'] ?? 0);
$utr     = trim($_POST['utr_number'] ?? '');

if ($amount <= 0) {
    echo json_encode(['status' => false, 'message' => 'Please enter a valid recharge amount.']);
    exit;
}

if (empty($utr) || strlen($utr) < 6) {
    echo json_encode(['status' => false, 'message' => 'Please enter a valid 12-digit UPI/Bank UTR number.']);
    exit;
}

// Screenshot Upload Handler (Optional)
$screenshot_url = null;
if (!empty($_FILES['screenshot']['name']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }
    $ext = strtolower(pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        $file_name = 'pay_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $upload_dir . $file_name)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $screenshot_url = $protocol . $_SERVER['HTTP_HOST'] . '/uploads/' . $file_name;
        }
    }
}

try {
    // 1. Check if UTR already submitted
    $stmtCheck = $pdo->prepare("SELECT id FROM payment_requests WHERE utr_number = ? LIMIT 1");
    $stmtCheck->execute([$utr]);
    if ($stmtCheck->fetch()) {
        echo json_encode(['status' => false, 'message' => 'This UTR/Transaction ID has already been submitted.']);
        exit;
    }

    // 2. Generate Single-Use Secure Hash Token for Email Action
    $approval_token = bin2hex(random_bytes(32));

    // 3. Insert Request
    $stmtIns = $pdo->prepare("
        INSERT INTO payment_requests (user_id, amount, utr_number, screenshot_url, status, approval_token, created_at)
        VALUES (?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $stmtIns->execute([$user_id, $amount, $utr, $screenshot_url, $approval_token]);
    $request_id = (int)$pdo->lastInsertId();

    // 4. Fetch User Details for Email Notification
    $stmtU = $pdo->prepare("SELECT phone, wallet_balance FROM users WHERE id = ? LIMIT 1");
    $stmtU->execute([$user_id]);
    $userData = $stmtU->fetch();
    $user_phone = $userData['phone'] ?? 'N/A';
    $user_wallet = $userData['wallet_balance'] ?? 0.00;

    // 5. Compose 1-Click Interactive Email to Admin
    $admin_email = 'support@wubachat.com';
    $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $base_url    = $protocol . $_SERVER['HTTP_HOST'];

    $approve_url = "{$base_url}/api/quick_action.php?action=approve&id={$request_id}&token={$approval_token}";
    $reject_url  = "{$base_url}/api/quick_action.php?action=reject&id={$request_id}&token={$approval_token}";

    $subject = "🔔 New Payment Received: ₹" . number_format($amount, 2) . " from {$user_phone}";

    $message_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; }
            .card { max-width: 550px; background: #ffffff; padding: 25px; border-radius: 12px; margin: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
            .header { border-bottom: 2px solid #25D366; padding-bottom: 15px; margin-bottom: 20px; }
            .badge { display: inline-block; background: #e8f5e9; color: #2e7d32; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 14px; }
            .row { margin: 10px 0; font-size: 15px; color: #333; }
            .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 15px; margin-right: 10px; }
            .btn-approve { background-color: #25D366; color: #ffffff !important; }
            .btn-reject { background-color: #e53935; color: #ffffff !important; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='header'>
                <span class='badge'>Wubachat Payment Alert</span>
                <h2 style='margin: 10px 0 0 0; color: #111;'>Recharge Request Submitted</h2>
            </div>
            <div class='row'><strong>User Phone:</strong> +{$user_phone}</div>
            <div class='row'><strong>Requested Amount:</strong> <span style='font-size: 18px; color: #2e7d32; font-weight: bold;'>₹" . number_format($amount, 2) . "</span></div>
            <div class='row'><strong>UTR / Ref Number:</strong> <code style='background: #eee; padding: 3px 6px; border-radius: 4px;'>{$utr}</code></div>
            <div class='row'><strong>Current Balance:</strong> ₹" . number_format($user_wallet, 2) . "</div>
            " . (!empty($screenshot_url) ? "<div class='row'><a href='{$screenshot_url}' target='_blank'>📎 View Payment Screenshot</a></div>" : "") . "
            <hr style='margin: 25px 0; border: none; border-top: 1px solid #eee;'>
            <div style='text-align: center;'>
                <a href='{$approve_url}' class='btn btn-approve'>✅ Approve & Credit Wallet</a>
                <a href='{$reject_url}' class='btn btn-reject'>❌ Reject</a>
            </div>
        </div>
    </body>
    </html>
    ";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Wubachat System <no-reply@" . $_SERVER['HTTP_HOST'] . ">\r\n";

    @mail($admin_email, $subject, $message_body, $headers);

    echo json_encode([
        'status'  => true,
        'message' => 'Payment details submitted successfully! Wallet will be credited once verified.'
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Submission failed: ' . $e->getMessage()]);
}
?>