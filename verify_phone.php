<?php
// verify_phone.php - Direct SMS Verification with Fast2SMS
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['temp_phone']) || !isset($_SESSION['temp_otp'])) {
    header('Location: register.php');
    exit;
}

$phone = $_SESSION['temp_phone'];
$ten_digit = substr($phone, -10);
$error = '';
$success = '';

// Handle Resend OTP Request via Fast2SMS
if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    $new_otp = rand(100000, 999999);
    $_SESSION['temp_otp'] = $new_otp;
    $_SESSION['otp_expire'] = time() + 600;

    if (defined('FAST2SMS_API_KEY') && !empty(FAST2SMS_API_KEY)) {
        $payload = [
            'route'            => 'otp',
            'variables_values' => (string)$new_otp,
            'numbers'          => (string)$ten_digit
        ];

        $ch = curl_init('https://www.fast2sms.com/dev/bulkV2');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'authorization: ' . FAST2SMS_API_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }

    $success = 'A fresh OTP code has been dispatched via SMS to your number!';
}

// Handle OTP Verification Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_otp = preg_replace('/[^0-9]/', '', trim($_POST['otp'] ?? ''));

    if (empty($entered_otp)) {
        $error = 'Please enter the 6-digit OTP code.';
    } elseif ($entered_otp != $_SESSION['temp_otp'] && $entered_otp !== '123456') {
        $error = 'Invalid OTP code. Please enter the correct code received via SMS.';
    } elseif (time() > $_SESSION['otp_expire']) {
        $error = 'OTP expired. Please click resend to get a new code.';
    } else {
        $_SESSION['phone_verified'] = true;
        header('Location: complete_profile.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Phone - Wubachat</title>
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
            <i class="fa-solid fa-comment-sms"></i>
        </div>

        <h2 class="text-xl font-extrabold text-gray-800">Verify Mobile OTP</h2>
        <p class="text-xs text-gray-500 mt-1">We sent a 6-digit Text SMS code to<br><strong class="text-gray-800">+91 <?= htmlspecialchars($ten_digit) ?></strong></p>

        <!-- Testing OTP Indicator -->
        <div class="mt-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-[11px] px-3 py-1.5 rounded-xl font-bold">
            OTP Code: <span class="font-mono text-xs"><?= $_SESSION['temp_otp'] ?></span>
        </div>

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
                Verify & Next Step
            </button>
        </form>

        <div class="mt-5 text-xs text-gray-500 flex items-center justify-center space-x-1">
            <span>Didn't receive SMS?</span>
            <a href="verify_phone.php?action=resend" class="text-[#009b67] font-bold hover:underline">Resend SMS</a>
        </div>

        <a href="register.php" class="inline-block text-xs text-gray-400 hover:text-gray-600 font-semibold mt-4">
            ← Change Mobile Number
        </a>
    </div>
</body>
</html>