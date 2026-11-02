<?php
// forgot_password.php - Reset Password via Email OTP
session_start();
require_once __DIR__ . '/config.php';

$error_msg   = '';
$success_msg = '';
$step        = 1; // Step 1: Enter Email, Step 2: Enter OTP & New Password

// Auto-create password_resets table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(191) NOT NULL,
            `otp` VARCHAR(10) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

// STEP 1: SEND OTP TO EMAIL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid registered email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $otp = (string) rand(100000, 999999);
            
            // Store OTP in database
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            $stmtInsert = $pdo->prepare("INSERT INTO password_resets (email, otp) VALUES (?, ?)");
            $stmtInsert->execute([$email, $otp]);

            // Send Email using PHP mail()
            $to      = $email;
            $subject = "Wubachat - Password Reset Verification Code";
            $message = "Hello " . htmlspecialchars($user['name']) . ",\r\n\r\n";
            $message .= "Your 6-digit OTP verification code to reset your Wubachat password is: " . $otp . "\r\n\r\n";
            $message .= "This OTP is valid for 15 minutes. If you did not request this, please ignore this email.\r\n\r\n";
            $message .= "Team Wubachat\r\nhttps://wubachat.com";

            $headers = "From: no-reply@wubachat.com\r\n" .
                       "Reply-To: support@wubachat.com\r\n" .
                       "X-Mailer: PHP/" . phpversion();

            @mail($to, $subject, $message, $headers);

            $_SESSION['reset_email'] = $email;
            $success_msg = 'A 6-digit verification code has been sent to your email.';
            $step = 2;
        } else {
            $error_msg = 'No user account found with this email address.';
        }
    }
}

// STEP 2: VERIFY OTP & UPDATE PASSWORD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $email        = $_SESSION['reset_email'] ?? '';
    $otp          = trim($_POST['otp'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (empty($email)) {
        $error_msg = 'Session expired. Please request OTP again.';
        $step = 1;
    } elseif (empty($otp) || strlen($otp) !== 6) {
        $error_msg = 'Please enter a valid 6-digit OTP code.';
        $step = 2;
    } elseif (strlen($new_password) < 6) {
        $error_msg = 'Password must be at least 6 characters long.';
        $step = 2;
    } elseif ($new_password !== $confirm_pass) {
        $error_msg = 'Passwords do not match. Please re-enter.';
        $step = 2;
    } else {
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND otp = ? LIMIT 1");
        $stmt->execute([$email, $otp]);
        $reset_record = $stmt->fetch();

        if ($reset_record) {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmtUpdate = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmtUpdate->execute([$hash, $email]);

            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            unset($_SESSION['reset_email']);

            $success_msg = 'Password changed successfully! You can now login with your new password.';
            $step = 3;
        } else {
            $error_msg = 'Invalid or expired OTP code entered.';
            $step = 2;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Wubachat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-3xl p-8 border border-gray-100 shadow-2xl space-y-6">
        
        <div class="text-center space-y-1">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-emerald-50 text-[#009b67] text-2xl shadow-sm mb-1">
                <i class="fa-solid fa-key"></i>
            </div>
            <h1 class="text-2xl font-black text-gray-900 tracking-tight">Forgot Password</h1>
            <p class="text-xs text-gray-500 font-medium">Reset your Wubachat account access</p>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="p-3.5 bg-red-50 border border-red-200 text-red-600 rounded-2xl text-xs font-semibold flex items-center space-x-2">
                <i class="fa-solid fa-circle-exclamation shrink-0"></i>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            <div class="p-3.5 bg-emerald-50 border border-emerald-200 text-[#009b67] rounded-2xl text-xs font-semibold flex items-center space-x-2">
                <i class="fa-solid fa-circle-check shrink-0"></i>
                <span><?= htmlspecialchars($success_msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- STEP 1: ENTER EMAIL -->
        <?php if ($step === 1): ?>
            <form method="POST" action="forgot_password.php" class="space-y-4">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1.5">Registered Email Address</label>
                    <div class="flex items-center bg-gray-50 border border-gray-200 rounded-2xl px-3.5 py-3 focus-within:bg-white focus-within:ring-1 focus-within:ring-[#009b67] transition">
                        <i class="fa-solid fa-envelope text-gray-400 text-xs mr-2.5"></i>
                        <input type="email" name="email" placeholder="name@example.com" required class="w-full bg-transparent text-xs font-semibold focus:outline-none text-gray-800">
                    </div>
                </div>

                <button type="submit" name="send_otp" class="w-full bg-[#009b67] hover:bg-[#008357] text-white font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-lg transition flex items-center justify-center space-x-2">
                    <span>Send Verification Code</span>
                    <i class="fa-solid fa-paper-plane text-xs"></i>
                </button>
            </form>
        <?php endif; ?>

        <!-- STEP 2: ENTER OTP & NEW PASSWORD -->
        <?php if ($step === 2): ?>
            <form method="POST" action="forgot_password.php" class="space-y-4">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1.5">6-Digit Email Code</label>
                    <input type="text" name="otp" maxlength="6" placeholder="• • • • • •" required class="w-full text-center text-xl font-black tracking-[0.3em] py-2.5 bg-gray-50 border border-gray-200 rounded-2xl focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#009b67]">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1.5">New Password</label>
                    <input type="password" name="new_password" placeholder="Enter new password (min 6 chars)" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl text-xs font-semibold focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#009b67]">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1.5">Confirm New Password</label>
                    <input type="password" name="confirm_password" placeholder="Re-enter new password" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl text-xs font-semibold focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#009b67]">
                </div>

                <button type="submit" name="reset_password" class="w-full bg-[#009b67] hover:bg-[#008357] text-white font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-lg transition flex items-center justify-center space-x-2">
                    <span>Set New Password</span>
                    <i class="fa-solid fa-lock text-xs"></i>
                </button>
            </form>
        <?php endif; ?>

        <!-- STEP 3: SUCCESS -->
        <?php if ($step === 3): ?>
            <div class="pt-2">
                <a href="login.php" class="w-full bg-[#009b67] hover:bg-[#008357] text-white font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-lg transition flex items-center justify-center space-x-2">
                    <span>Go to Login</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>
        <?php endif; ?>

        <div class="text-center text-xs text-gray-500 font-medium">
            Remember your password? 
            <a href="login.php" class="text-[#009b67] font-bold hover:underline">Back to Login</a>
        </div>
    </div>
</body>
</html>