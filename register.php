<?php
// register.php - Instant & Secure Email OTP Registration with WhatsApp Compliance Confirmation
session_start();
require_once __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

    if (!$email) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            // Check if email already registered
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'This email is already registered. Please proceed to login.';
            } else {
                $otp = rand(100000, 999999);

                // Dispatch Verification Code via Server Mail Gateway
                $subject = "Your Wubachat Verification Code: {$otp}";
                $message = "
                <html>
                <head>
                    <title>Wubachat Email Verification</title>
                </head>
                <body style='font-family: Arial, sans-serif; background-color: #f4f6f8; padding: 20px; color: #333;'>
                    <div style='max-width: 480px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 16px; border: 1px solid #e2e8f0; text-align: center;'>
                        <h2 style='color: #009b67; margin-bottom: 8px;'>Wubachat Security</h2>
                        <p style='font-size: 14px; color: #64748b;'>Use the following code to complete your signup process:</p>
                        <div style='font-size: 32px; font-weight: 800; letter-spacing: 6px; color: #1e293b; background: #f0fdf4; border: 1px dashed #009b67; padding: 15px; border-radius: 12px; margin: 25px 0;'>
                            {$otp}
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

                $_SESSION['temp_email'] = $email;
                $_SESSION['temp_otp']   = $otp;
                $_SESSION['otp_expire'] = time() + 600;

                header('Location: verify_email.php');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Wubachat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#f0f4f9] min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-8 border border-gray-100">
        <div class="text-center mb-6">
            <img src="/assets/logo.png" alt="Wubachat" class="h-10 mx-auto mb-2" onerror="this.src='https://placehold.co/140x45/009b67/ffffff?text=Wubachat'">
            <h2 class="text-xl font-extrabold text-gray-800">Create Wubachat Account</h2>
            <p class="text-xs text-gray-400 mt-1">Fast & Secure Business Messaging Platform</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-xs p-3 rounded-2xl mb-4 font-semibold">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form id="email-submit-form" method="POST" onsubmit="openComplianceModal(event)" class="space-y-4">
            <div>
                <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Email Address</label>
                
                <div class="flex items-center bg-gray-50 border border-gray-200 rounded-2xl overflow-hidden focus-within:ring-1 focus-within:ring-[#009b67] focus-within:bg-white transition">
                    <span class="px-3.5 py-3 bg-gray-100 text-gray-500 text-sm border-r border-gray-200 flex items-center">
                        <i class="fa-solid fa-envelope"></i>
                    </span>
                    <input type="email" id="email-input" name="email" required placeholder="Enter your business email" class="w-full px-4 py-3 bg-transparent text-xs font-semibold focus:outline-none">
                </div>
                <p class="text-[10px] text-gray-400 mt-1">We will send a 6-digit verification code to your email.</p>
            </div>

            <button type="submit" id="submit-btn" class="w-full bg-[#009b67] hover:bg-[#008357] text-white font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-lg transition flex items-center justify-center space-x-2">
                <span>Send Verification Code</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </form>

        <p class="text-center text-xs text-gray-500 mt-6">
            Already registered? <a href="login.php" class="text-[#009b67] font-bold hover:underline">Log in here</a>
        </p>
    </div>

    <!-- WHATSAPP COMPLIANCE POPUP MODAL (ENGLISH) -->
    <div id="whatsapp-compliance-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4 border border-gray-100">
            <div class="flex items-center space-x-3 border-b border-gray-100 pb-3">
                <span class="w-10 h-10 rounded-2xl bg-emerald-50 text-[#009b67] flex items-center justify-center text-lg font-bold">
                    <i class="fa-brands fa-whatsapp"></i>
                </span>
                <div>
                    <h3 class="text-sm font-black text-gray-800">WhatsApp Number Compliance Check</h3>
                    <p class="text-[10px] text-gray-400">Important requirement before registration</p>
                </div>
            </div>

            <div class="space-y-3 text-xs text-gray-600 leading-relaxed">
                <p class="font-bold text-gray-800">Have you permanently deleted or deactivated this phone number from the official WhatsApp / WhatsApp Business mobile app?</p>
                <p class="text-[11px] bg-amber-50 text-amber-800 p-3 rounded-xl border border-amber-200">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> <strong>Note:</strong> If the number is currently active on any mobile phone application, Meta Cloud API connection will fail or get restricted.
                </p>
            </div>

            <div class="flex space-x-3 pt-2">
                <button type="button" onclick="closeComplianceModal()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-xs transition">
                    No, Go Back
                </button>
                <button type="button" onclick="confirmAndSubmitForm()" class="flex-1 bg-[#009b67] hover:bg-[#008357] text-white font-bold py-2.5 rounded-xl text-xs transition shadow-md">
                    Yes, Proceed
                </button>
            </div>
        </div>
    </div>

    <script>
        function openComplianceModal(e) {
            e.preventDefault();
            const email = document.getElementById('email-input').value.trim();
            if (!email) {
                alert('Please enter a valid email address.');
                return;
            }
            // Show popup modal in English
            const modal = document.getElementById('whatsapp-compliance-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeComplianceModal() {
            const modal = document.getElementById('whatsapp-compliance-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function confirmAndSubmitForm() {
            closeComplianceModal();
            const btn = document.getElementById('submit-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Sending OTP...';
            document.getElementById('email-submit-form').submit();
        }
    </script>
</body>
</html>