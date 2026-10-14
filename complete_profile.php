<?php
// complete_profile.php - Complete Profile After Email Verification with Strict User Isolation
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['email_verified']) || empty($_SESSION['temp_email'])) {
    header('Location: register.php');
    exit;
}

$email = $_SESSION['temp_email'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['name'] ?? '');
    $phone_raw = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';

    // Clean and validate 10-digit mobile number
    $clean_phone = preg_replace('/[^0-9]/', '', $phone_raw);
    if (strlen($clean_phone) === 12 && substr($clean_phone, 0, 2) === '91') {
        $clean_phone = substr($clean_phone, 2);
    } elseif (strlen($clean_phone) === 11 && strpos($clean_phone, '0') === 0) {
        $clean_phone = substr($clean_phone, 1);
    }

    if (empty($full_name) || empty($password) || strlen($clean_phone) !== 10) {
        $error = 'Please enter your Full Name, a valid 10-digit Mobile Number, and Password.';
    } else {
        try {
            $formatted_phone = '91' . $clean_phone;

            // Check if phone or email already registered
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR phone LIKE ? LIMIT 1");
            $stmt->execute([$email, "%$clean_phone%"]);
            if ($stmt->fetch()) {
                $error = 'An account with this email or mobile number already exists.';
            } else {
                $hash_pass = password_hash($password, PASSWORD_BCRYPT);
                $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

                $data = [];
                if (in_array('name', $columns))          $data['name'] = $full_name;
                elseif (in_array('full_name', $columns)) $data['full_name'] = $full_name;
                
                if (in_array('email', $columns))         $data['email'] = $email;
                if (in_array('phone', $columns))         $data['phone'] = $formatted_phone;
                elseif (in_array('mobile', $columns))    $data['mobile'] = $formatted_phone;

                if (in_array('password', $columns))      $data['password'] = $hash_pass;
                if (in_array('wallet_balance', $columns))$data['wallet_balance'] = 0.00;
                if (in_array('status', $columns))        $data['status'] = 'active';

                $colKeys = implode(', ', array_keys($data));
                $placeholders = implode(', ', array_fill(0, count($data), '?'));

                $insertStmt = $pdo->prepare("INSERT INTO users ($colKeys) VALUES ($placeholders)");
                $insertStmt->execute(array_values($data));

                $new_user_id = (int)$pdo->lastInsertId();

                // Ensure user_whatsapp_accounts table exists and map registered phone number strictly
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `user_whatsapp_accounts` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `user_id` INT NOT NULL,
                            `phone_number` VARCHAR(30) NOT NULL,
                            `phone_number_id` VARCHAR(100) NULL,
                            `waba_id` VARCHAR(100) NULL,
                            `access_token` TEXT NULL,
                            `status` VARCHAR(20) DEFAULT 'active',
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE KEY `uk_user_phone` (`user_id`, `phone_number`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    ");

                    $stmtWp = $pdo->prepare("
                        INSERT INTO user_whatsapp_accounts (user_id, phone_number, status) 
                        VALUES (?, ?, 'active') 
                        ON DUPLICATE KEY UPDATE status = 'active'
                    ");
                    $stmtWp->execute([$new_user_id, $formatted_phone]);
                } catch(Exception $ex) {}

                // Auto Login
                $_SESSION['user_id']        = $new_user_id;
                $_SESSION['user_name']      = $full_name;
                $_SESSION['email']          = $email;
                $_SESSION['wallet_balance'] = 0.00;

                unset($_SESSION['temp_email'], $_SESSION['temp_otp'], $_SESSION['email_verified']);

                header('Location: dashboard.php');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Registration failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Profile - Wubachat</title>
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
            <span class="bg-emerald-50 text-[#009b67] text-xs px-3 py-1 rounded-full font-bold">
                <i class="fa-solid fa-check-circle mr-1"></i> Email <?= htmlspecialchars($email) ?> Verified
            </span>
            <h2 class="text-xl font-extrabold text-gray-800 mt-3">Complete Your Profile</h2>
            <p class="text-xs text-gray-400 mt-1">Set your account details and password</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-xs p-3 rounded-2xl mb-4 font-semibold">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Full Name / Business Name</label>
                <input type="text" name="name" required placeholder="Enter full name" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-xs focus:outline-none focus:ring-1 focus:ring-[#009b67]">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Mobile Number</label>
                <div class="flex items-center bg-gray-50 border border-gray-200 rounded-2xl overflow-hidden focus-within:ring-1 focus-within:ring-[#009b67] focus-within:bg-white transition">
                    <span class="px-3.5 py-2.5 bg-gray-100 text-gray-700 text-xs font-bold border-r border-gray-200 flex items-center space-x-1">
                        <span>🇮🇳</span>
                        <span>+91</span>
                    </span>
                    <input type="tel" name="phone" maxlength="10" required placeholder="10-digit mobile number" class="w-full px-4 py-2.5 bg-transparent text-xs font-semibold focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Account Password</label>
                <input type="password" name="password" required placeholder="••••••••" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-xs focus:outline-none focus:ring-1 focus:ring-[#009b67]">
            </div>

            <button type="submit" class="w-full bg-[#009b67] hover:bg-[#008357] text-white font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-lg transition">
                Finish Registration & Open Console
            </button>
        </form>
    </div>
</body>
</html>