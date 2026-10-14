<?php
// api/quick_action.php - 1-Click Secure Email Approval Engine
require_once __DIR__ . '/../config.php';

$action = strtolower(trim($_GET['action'] ?? ''));
$id     = (int)($_GET['id'] ?? 0);
$token  = trim($_GET['token'] ?? '');

function renderResult($title, $message, $color = '#25D366') {
    echo "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1'>
        <title>{$title}</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .box { background: #fff; padding: 35px; border-radius: 12px; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
            h2 { color: {$color}; margin-top: 0; }
            p { color: #555; font-size: 16px; line-height: 1.5; }
        </style>
    </head>
    <body>
        <div class='box'>
            <h2>{$title}</h2>
            <p>{$message}</p>
        </div>
    </body>
    </html>";
    exit;
}

if (empty($action) || empty($id) || empty($token)) {
    renderResult('Invalid Request', 'Missing required parameters.', '#e53935');
}

try {
    // 1. Verify token & fetch pending record
    $stmt = $pdo->prepare("SELECT * FROM payment_requests WHERE id = ? AND approval_token = ? LIMIT 1");
    $stmt->execute([$id, $token]);
    $req = $stmt->fetch();

    if (!$req) {
        renderResult('Unauthorized', 'Invalid or expired action link.', '#e53935');
    }

    if ($req['status'] !== 'pending') {
        renderResult('Already Processed', "This transaction has already been marked as <strong>" . strtoupper($req['status']) . "</strong>.", '#f57c00');
    }

    $amount  = (float)$req['amount'];
    $user_id = (int)$req['user_id'];

    if ($action === 'approve') {
        $pdo->beginTransaction();

        // Credit User Balance
        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")->execute([$amount, $user_id]);

        // Invalidate token and mark approved
        $pdo->prepare("UPDATE payment_requests SET status = 'approved', approval_token = '' WHERE id = ?")->execute([$id]);

        $pdo->commit();

        renderResult('Payment Approved!', "₹" . number_format($amount, 2) . " has been credited to User #{$user_id}'s wallet successfully.", '#25D366');
    } elseif ($action === 'reject') {
        // Mark rejected
        $pdo->prepare("UPDATE payment_requests SET status = 'rejected', approval_token = '' WHERE id = ?")->execute([$id]);

        renderResult('Payment Rejected', "Request for ₹" . number_format($amount, 2) . " has been declined.", '#e53935');
    } else {
        renderResult('Unknown Action', 'Invalid action command.', '#e53935');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    renderResult('Server Error', $e->getMessage(), '#e53935');
}
?>