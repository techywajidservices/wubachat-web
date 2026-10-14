<?php
// api/recharge_wallet.php - Secure Pending Recharge Request & Admin Email Dispatcher
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => false, 'message' => 'Unauthorized session. Please login again.']);
        exit;
    }

    require_once __DIR__ . '/../config.php';

    if (!isset($pdo)) {
        throw new Exception("Database connection object (\$pdo) not found in config.php");
    }

    $user_id    = (int)$_SESSION['user_id'];
    $amount     = floatval($_POST['amount'] ?? 0);
    $utr_number = trim($_POST['utr_number'] ?? '');

    // Strict Minimum ₹100 Limit Enforced
    if ($amount < 100) {
        echo json_encode(['status' => false, 'message' => 'Minimum recharge amount is ₹100.00. Please enter ₹100 or more.']);
        exit;
    }

    if (empty($utr_number) || strlen($utr_number) < 6) {
        echo json_encode(['status' => false, 'message' => 'Please enter a valid 12-digit UPI Reference / UTR Number.']);
        exit;
    }

    // Ensure wallet_transactions table exists with correct schema
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `wallet_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `utr_number` VARCHAR(100) NULL,
            `type` VARCHAR(20) DEFAULT 'credit',
            `status` VARCHAR(20) DEFAULT 'pending',
            `approval_token` VARCHAR(64) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Check if approval_token column exists
    $cols = $pdo->query("SHOW COLUMNS FROM `wallet_transactions` LIKE 'approval_token'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `wallet_transactions` ADD COLUMN `approval_token` VARCHAR(64) NULL AFTER `status`");
    }

    // 1. Strict Duplicate UTR Check (Blocks duplicate UTR across pending, approved, or success)
    $stmt_check = $pdo->prepare("SELECT id FROM wallet_transactions WHERE utr_number = ? LIMIT 1");
    $stmt_check->execute([$utr_number]);
    if ($stmt_check->fetch()) {
        echo json_encode(['status' => false, 'message' => 'Fraud Alert: This UTR / Transaction Reference has already been submitted or used!']);
        exit;
    }

    // 2. Fetch User Details for Email & Notification
    $stmt_user = $pdo->prepare("SELECT phone, email, wallet_balance FROM users WHERE id = ? LIMIT 1");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch();

    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'User account not found in database.']);
        exit;
    }

    $user_phone  = $user['phone'] ?? 'N/A';
    $user_wallet = floatval($user['wallet_balance'] ?? 0);

    // 3. Generate Secure Approval Token
    $approval_token = bin2hex(random_bytes(32));

    // 4. Insert Transaction as PENDING (Admin must approve)
    $stmt_ins = $pdo->prepare("
        INSERT INTO wallet_transactions (user_id, amount, utr_number, type, status, approval_token, created_at) 
        VALUES (?, ?, ?, 'credit', 'pending', ?, NOW())
    ");
    $stmt_ins->execute([$user_id, $amount, $utr_number, $approval_token]);
    $request_id = (int)$pdo->lastInsertId();

    // 5. Compose and Dispatch 1-Click Interactive Email to Admin
    $admin_email = defined('ADMIN_NOTIFICATION_EMAIL') ? ADMIN_NOTIFICATION_EMAIL : 'support@wubachat.com';
    $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $base_url    = $protocol . $_SERVER['HTTP_HOST'];

    $approve_url = "{$base_url}/api/quick_action.php?action=approve&id={$request_id}&token={$approval_token}";
    $reject_url  = "{$base_url}/api/quick_action.php?action=reject&id={$request_id}&token={$approval_token}";

    $subject = "🔔 New Payment Pending Approval: ₹" . number_format($amount, 2) . " from +{$user_phone}";

    $message_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; }
            .card { max-width: 550px; background: #ffffff; padding: 25px; border-radius: 12px; margin: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
            .header { border-bottom: 2px solid #25D366; padding-bottom: 15px; margin-bottom: 20px; }
            .badge { display: inline-block; background: #fff3cd; color: #856404; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 14px; }
            .row { margin: 10px 0; font-size: 15px; color: #333; }
            .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 15px; margin-right: 10px; }
            .btn-approve { background-color: #25D366; color: #ffffff !important; }
            .btn-reject { background-color: #e53935; color: #ffffff !important; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='header'>
                <span class='badge'>Wubachat Payment Verification</span>
                <h2 style='margin: 10px 0 0 0; color: #111;'>New Recharge Request</h2>
            </div>
            <div class='row'><strong>User Phone:</strong> +{$user_phone}</div>
            <div class='row'><strong>Requested Amount:</strong> <span style='font-size: 18px; color: #2e7d32; font-weight: bold;'>₹" . number_format($amount, 2) . "</span></div>
            <div class='row'><strong>UTR / Ref Number:</strong> <code style='background: #eee; padding: 3px 6px; border-radius: 4px;'>{$utr_number}</code></div>
            <div class='row'><strong>User Current Balance:</strong> ₹" . number_format($user_wallet, 2) . "</div>
            <hr style='margin: 25px 0; border: none; border-top: 1px solid #eee;'>
            <div style='text-align: center;'>
                <a href='{$approve_url}' class='btn btn-approve'>✅ Approve & Credit Wallet</a>
                <a href='{$reject_url}' class='btn btn-reject'>❌ Reject</a>
            </div>
        </div>
    </body>
    </html>
    ";

    $domain = $_SERVER['HTTP_HOST'];
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Wubachat Notifications <noreply@{$domain}>\r\n";
    $headers .= "Reply-To: noreply@{$domain}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // Attempt to dispatch mail
    @mail($admin_email, $subject, $message_body, $headers);

    echo json_encode([
        'status'  => true,
        'message' => 'Payment details submitted successfully! Your wallet will be credited once verified by admin.'
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Submission Error: ' . $e->getMessage()]);
}
?>