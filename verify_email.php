<?php
// verify_email.php - Universal Dual-Flow Email OTP Verification (Registration & Direct Login)
session_start();
require_once __DIR__ . '/config.php';

// Auto-detect authentication flow (Registration or Login)
$is_login_flow = isset($_SESSION['login_otp_email']);
$is_reg_flow   = isset($_SESSION['temp_email']);

if (!$is_login_flow && !$is_reg_flow) {
    header('Location: login.php');
    exit;
}

$email = $is_login_flow ? $_SESSION['login_otp_email'] : $_SESSION['temp_email'];
$error = '';
$success = '';

// Handle Resend OTP Request via Mail
if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    $new_otp = (string)rand(100000, 999999);

    if ($is_login_flow) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `email_login_otps` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(191) NOT NULL,
                `otp` VARCHAR(10) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $pdo->prepare("DELETE FROM email_login_otps WHERE email = ?")->execute([$email]);
        $stmtIns = $pdo->prepare("INSERT INTO email_login_otps (email, otp) VALUES (?, ?)");
        $stmtIns->execute([$email, $new_otp]);
        $_SESSION['login_otp_expire'] = time() + 600;
    } else {
        $_SESSION['temp_otp'] = $new_otp;
        $_SESSION['otp_expire'] = time() + 600;
    }

    $subject = "Your New Wubachat Verification Code: {$new_otp}";
    $message = "
    <html>
    <head>
        <title>Wubachat Email Verification</title>
    </head>
    <body style='font-family: Arial, sans-serif; background-color: #f4f6f8; padding: 20px; color: #333;'>
        <div style='max-width: 480px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 16px; border: 1px solid #e2e8f0; text-align: center;'>
            <h2 style='color: #009b67; margin-bottom: 8px;'>Wubachat Security</h2>
            <p style='font-size: 14px; color: #64748b;'>Your new verification code is:</p>
            <div style='font-size: 32px; font-weight: 800; letter-spacing: 6px; color: #1e293b; background: #f0fdf4; border: 1px dashed #009b67; padding: 15px; border-radius: 12px; margin: 25px 0;'>
                {$new_otp}
            </div>
            <p style='font-size: 12px; color: #94a3b8;'>This code is valid for 10 minutes. Please do not share it with anyone.</p>
        </div>
    </body>
    </html>
    ";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Wubachat Security <no-reply@wubachat.com>\r\n";

    @mail($email, $subject, $message, $headers);
    $success = 'A fresh verification code has been sent to your email inbox!';
}

// Handle OTP Verification Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_otp = preg_replace('/[^0-9]/', '', trim($_POST['otp'] ?? ''));

    if (empty($entered_otp) || strlen($entered_otp) !== 6) {
        $error = 'Please enter a valid 6-digit OTP code.';
    } else {
        // CASE A: LOGIN FLOW VERIFICATION
        if ($is_login_flow) {
            $stmt = $pdo->prepare("SELECT * FROM email_login_otps WHERE email = ? AND otp = ? LIMIT 1");
            $stmt->execute([$email, $entered_otp]);
            $record = $stmt->fetch();

            if ($record) {
                $stmtUser = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                $stmtUser->execute([$email]);
                $user = $stmtUser->fetch();

                if ($user) {
                    $pdo->prepare("DELETE FROM email_login_otps WHERE email = ?")->execute([$email]);
                    unset($_SESSION['login_otp_email'], $_SESSION['login_otp_expire']);

                    $_SESSION['user_id']        = $user['id'];
                    $_SESSION['user_name']      = $user['name'] ?? $user['full_name'] ?? 'User';
                    $_SESSION['email']          = $user['email'];
                    $_SESSION['wallet_balance'] = $user['wallet_balance'] ?? 0.00;

                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'Invalid or expired OTP code. Please enter the correct code received on your email.';
            }
        } 
        // CASE B: REGISTRATION FLOW VERIFICATION
        else {
            if ($entered_otp != ($_SESSION['temp_otp'] ?? '')) {
                $error = 'Invalid OTP code. Please enter the correct code received on your email.';
            } elseif (time() > ($_SESSION['otp_expire'] ?? 0)) {
                $error = 'OTP expired. Please click resend to get a new code.';
            } else {
                $_SESSION['email_verified'] = true;
                header('Location: complete_profile.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - Wubachat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#f0f4f9] min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-sm w-full p-8 border border-gray-100 text-center">
        <div class="w-14 h-14 bg-emerald-50 text-[#009b67] rounded-3xl mx-auto flex items-center justify-center text-xl mb-4 shadow-sm">
            <i class="fa-solid fa-envelope-circle-check"></i>
        </div>

        <h2 class="text-xl font-extrabold text-gray-800">Verify Email OTP</h2>
        <p class="text-xs text-gray-500 mt-1">We sent a 6-digit verification code to<br><strong class="text-gray-800"><?= htmlspecialchars($email) ?></strong></p>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-xs p-3 rounded-2xl my-4 font-semibold text-left">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-[#009b67] text-xs p-3 rounded-2xl my-4 font-semibold text-left">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5 mt-6">
            <div>
                <input type="text" name="otp" id="otp-input" maxlength="6" autofocus required placeholder="• • • • • •" class="w-full text-center text-2xl font-black tracking-[0.5em] py-3 bg-gray-50 border border-gray-200 rounded-2xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#009b67]">
            </div>

            <button type="submit" class="w-full bg-[#009b67] hover:bg-[#008357] text-white font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-lg transition">
                <?= $is_login_flow ? 'Verify & Enter Dashboard' : 'Verify & Next Step' ?>
            </button>
        </form>

        <div class="mt-5 text-xs text-gray-500 flex items-center justify-center space-x-1">
            <span>Didn't receive email?</span>
            <a href="verify_email.php?action=resend" class="text-[#009b67] font-bold hover:underline">Resend OTP</a>
        </div>

        <a href="<?= $is_login_flow ? 'login.php' : 'register.php' ?>" class="inline-block text-xs text-gray-400 hover:text-gray-600 font-semibold mt-4">
            ← <?= $is_login_flow ? 'Back to Login' : 'Change Email Address' ?>
        </a>
    </div>
</body>
</html>