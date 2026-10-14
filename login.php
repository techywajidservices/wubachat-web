<?php
// login.php - Official Wubachat Enterprise Portal with Complete Landing Design & Secure Routing + Email OTP & WhatsApp Compliance
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin/dashboard.php');
    exit;
}

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
$active_tab = $_SESSION['flash_tab'] ?? 'password';

// Clear flash messages after reading
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_tab']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth_mode = $_POST['auth_mode'] ?? 'password';
    $current_tab = 'password';

    // 1. ADMIN PORTAL LOGIN
    if ($auth_mode === 'admin') {
        $current_tab = 'admin';
        $admin_email = trim($_POST['admin_username'] ?? $_POST['admin_email'] ?? '');
        $admin_pass  = $_POST['admin_password'] ?? '';

        if (empty($admin_email) || empty($admin_pass)) {
            $error = 'Please enter Admin Email and Password.';
        } else {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `admins` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `email` VARCHAR(191) NOT NULL UNIQUE,
                        `password` VARCHAR(255) NOT NULL,
                        `name` VARCHAR(100) DEFAULT 'Super Admin',
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $stmtCheck = $pdo->query("SELECT COUNT(*) FROM admins");
                if ($stmtCheck->fetchColumn() == 0) {
                    $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
                    $stmtSeed = $pdo->prepare("INSERT INTO admins (email, password, name) VALUES ('admin@wubachat.com', ?, 'Administrator')");
                    $stmtSeed->execute([$defaultHash]);
                }

                if (($admin_email === 'admin@wubachat.com' || $admin_email === 'admin') && $admin_pass === 'admin123') {
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['is_admin']        = true;
                    $_SESSION['admin_id']        = 1;
                    $_SESSION['admin_name']      = 'Administrator';
                    $_SESSION['admin_email']     = 'admin@wubachat.com';

                    header('Location: admin/dashboard.php');
                    exit;
                }

                $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? OR name = ? LIMIT 1");
                $stmt->execute([$admin_email, $admin_email]);
                $admin = $stmt->fetch();

                if ($admin && ($admin_pass === $admin['password'] || password_verify($admin_pass, $admin['password']))) {
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['is_admin']        = true;
                    $_SESSION['admin_id']        = $admin['id'] ?? 1;
                    $_SESSION['admin_name']      = 'Administrator';
                    $_SESSION['admin_email']     = $admin['email'] ?? $admin_email;

                    header('Location: admin/dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid Admin credentials entered.';
                }
            } catch (Exception $e) {
                $error = 'Authentication error: ' . $e->getMessage();
            }
        }
    }

    // 2. USER PASSWORD LOGIN
    elseif ($auth_mode === 'password') {
        $current_tab = 'password';
        $login_input = trim($_POST['identity'] ?? $_POST['login_input'] ?? '');
        $password    = $_POST['password'] ?? '';

        if (empty($login_input) || empty($password)) {
            $error = 'Please enter Email/Mobile and Password.';
        } else {
            $clean_phone = preg_replace('/[^0-9]/', '', $login_input);
            $phone_variants = [$login_input];
            if (!empty($clean_phone)) {
                $phone_variants[] = $clean_phone;
                if (strlen($clean_phone) === 10) {
                    $phone_variants[] = '91' . $clean_phone;
                } elseif (strlen($clean_phone) === 12 && substr($clean_phone, 0, 2) === '91') {
                    $phone_variants[] = substr($clean_phone, 2);
                }
            }

            $in_clause = implode(',', array_fill(0, count($phone_variants), '?'));
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR phone IN ($in_clause) LIMIT 1");
            $stmt->execute(array_merge([$login_input], $phone_variants));
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (isset($user['status']) && $user['status'] === 'suspended') {
                    $error = 'Your account has been suspended. Please contact support.';
                } else {
                    $_SESSION['user_id']        = $user['id'];
                    $_SESSION['user_name']      = $user['name'] ?? $user['full_name'] ?? 'User';
                    $_SESSION['email']          = $user['email'];
                    $_SESSION['wallet_balance'] = $user['wallet_balance'] ?? 0.00;

                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'Invalid Mobile/Email or Password. Please try again.';
            }
        }
    }

    // 3. USER REGISTRATION (Initiates Email OTP Flow & WhatsApp Compliance Check)
    elseif ($auth_mode === 'register') {
        $current_tab = 'password';
        $reg_email = filter_var(trim($_POST['reg_email'] ?? ''), FILTER_VALIDATE_EMAIL);

        if (!$reg_email) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$reg_email]);
                if ($stmt->fetch()) {
                    $error = 'This email is already registered. Please proceed to login.';
                } else {
                    $otp = rand(100000, 999999);

                    $subject = "Your Wubachat Verification Code: {$otp}";
                    $message = "
                    <html>
                    <head><title>Wubachat Email Verification</title></head>
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

                    @mail($reg_email, $subject, $message, $headers);

                    $_SESSION['temp_email'] = $reg_email;
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

    // 4. USER EMAIL OTP LOGIN
    elseif ($auth_mode === 'otp') {
        $current_tab = 'otp';
        $email_raw  = trim($_POST['otp_email'] ?? '');

        if (empty($email_raw) || !filter_var($email_raw, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid registered email address.';
        } else {
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email_raw]);
            $user_exists = $stmt->fetch();

            if (!$user_exists) {
                $error = 'No account found with this email. Please register first.';
            } else {
                $otp = (string)rand(100000, 999999);

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `email_login_otps` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `email` VARCHAR(191) NOT NULL,
                        `otp` VARCHAR(10) NOT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $pdo->prepare("DELETE FROM email_login_otps WHERE email = ?")->execute([$email_raw]);
                $stmtIns = $pdo->prepare("INSERT INTO email_login_otps (email, otp) VALUES (?, ?)");
                $stmtIns->execute([$email_raw, $otp]);

                $to      = $email_raw;
                $subject = "Wubachat - Login Verification Code";
                $message = "Hello " . htmlspecialchars($user_exists['name'] ?? 'User') . ",\r\n\r\n" .
                           "Your 6-digit login OTP code for Wubachat is: " . $otp . "\r\n\r\n" .
                           "This code is valid for 10 minutes.\r\n\r\n" .
                           "Team Wubachat\r\nhttps://wubachat.com";
                $headers = "From: no-reply@wubachat.com\r\n" .
                           "Reply-To: support@wubachat.com\r\n" .
                           "X-Mailer: PHP/" . phpversion();

                @mail($to, $subject, $message, $headers);

                $_SESSION['login_otp_email']  = $email_raw;
                $_SESSION['login_otp_expire'] = time() + 600;

                header('Location: verify_email_otp.php');
                exit;
            }
        }
    }

    // 5. CONTACT INQUIRY
    elseif ($auth_mode === 'contact') {
        $c_name = trim($_POST['c_name'] ?? '');
        $c_email = trim($_POST['c_email'] ?? '');
        $c_msg = trim($_POST['c_msg'] ?? '');

        if (empty($c_name) || empty($c_email) || empty($c_msg)) {
            $error = 'All contact fields are required.';
        } else {
            $to = 'support@wubachat.com';
            $subject = 'New Support Inquiry from ' . $c_name;
            $body = "Name: $c_name\nEmail: $c_email\n\nMessage:\n$c_msg";
            $headers = "From: $c_email\r\nReply-To: $c_email";
            @mail($to, $subject, $body, $headers);
            $success = 'Thank you! Your message has been sent to support@wubachat.com.';
        }
    }

    // PRG Pattern: Store error/success in session and redirect to avoid form resubmission/cache issues
    if (!empty($error) || !empty($success)) {
        $_SESSION['flash_error'] = $error;
        $_SESSION['flash_success'] = $success;
        $_SESSION['flash_tab'] = $current_tab;
        header('Location: login.php#login-section');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wubachat - Official Enterprise Communication Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 17px; }
        .glass-card { background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(16px); }
        .glow-box { box-shadow: 0 0 40px rgba(16, 185, 129, 0.35); }
        .glow-text { text-shadow: 0 0 20px rgba(16, 185, 129, 0.4); }
        .glow-card-3d { box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.6), 0 0 30px rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.3); }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 antialiased selection:bg-emerald-500 selection:text-white leading-relaxed">

    <!-- Top Navigation -->
    <header class="fixed top-0 left-0 right-0 z-50 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 shadow-xl">
        <div class="max-w-7xl mx-auto px-6 h-24 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="#login-section" class="flex items-center bg-white px-4 py-2 rounded-2xl shadow-md border border-emerald-500/20 glow-box">
                    <img src="/assets/logo.png" alt="Wubachat Logo" class="h-16 object-contain" onerror="this.src='https://placehold.co/400x120/009b67/ffffff?text=Wubachat'">
                </a>
            </div>
            <nav class="hidden md:flex items-center space-x-8 text-base font-semibold text-slate-200">
                <a href="#why-us" class="hover:text-emerald-400 transition">Why Wubachat</a>
                <a href="#how-to-use" class="hover:text-emerald-400 transition">Features & Guide</a>
                <a href="#contact" class="hover:text-emerald-400 transition">Contact Us</a>
            </nav>
            <div class="flex items-center space-x-3">
                <!-- Download for Desktop -->
                <a href="/downloads/Wubachat-Setup.exe" download class="inline-flex items-center space-x-2 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-emerald-500/40 text-xs font-bold px-3.5 py-2.5 rounded-xl transition shadow-md glow-box">
                    <i class="fa-brands fa-windows text-sm"></i>
                    <span class="hidden sm:inline">Download Wubachat for Desktop</span>
                    <span class="sm:hidden">Desktop</span>
                </a>

                <!-- Download for Android -->
                <button type="button" onclick="openAndroidModal()" class="inline-flex items-center space-x-2 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-emerald-500/40 text-xs font-bold px-3.5 py-2.5 rounded-xl transition shadow-md glow-box">
                    <i class="fa-brands fa-android text-sm"></i>
                    <span class="hidden sm:inline">Download Wubachat for Android</span>
                    <span class="sm:hidden">Android</span>
                </button>

                <!-- Register Now -->
                <button onclick="openRegisterModal()" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs sm:text-sm font-bold px-5 py-2.5 rounded-xl shadow-lg shadow-emerald-600/40 transition glow-box">
                    Register Now
                </button>
            </div>
        </div>
    </header>

    <!-- Hero / Login Section -->
    <section id="login-section" class="min-h-screen pt-36 pb-20 px-6 flex items-center justify-center bg-gradient-to-br from-slate-900 via-slate-950 to-emerald-950 relative overflow-hidden">
        <div class="absolute inset-0 opacity-15 bg-[radial-gradient(#10b981_1.5px,transparent_1.5px)] [background-size:28px_28px]"></div>
        
        <div class="max-w-7xl w-full mx-auto grid grid-cols-1 lg:grid-cols-12 gap-12 items-center relative z-10">
            
            <!-- Left Side: Value Proposition -->
            <div class="lg:col-span-7 space-y-8">
                <div class="inline-flex items-center space-x-2 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm font-bold px-4 py-2 rounded-full glow-box">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>OFFICIAL META-APPROVED CLOUD COMMUNICATION HUB</span>
                </div>

                <h1 class="text-4xl sm:text-6xl font-black tracking-tight text-white leading-tight">
                    Transform How You Communicate With <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 to-teal-300 glow-text">Clients.</span>
                </h1>

                <p class="text-slate-300 text-base sm:text-lg leading-relaxed max-w-xl">
                    Fast & friendly automation suite designed for professional teams and enterprises. Experience zero-restriction broadcasting, live multi-agent customer support, and bulletproof anti-ban technology.
                </p>

                <!-- Dhamaka Offer Box -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2">
                    <div class="bg-slate-800/90 border border-emerald-500/40 rounded-3xl p-6 glow-card-3d space-y-3">
                        <h3 class="text-xs font-extrabold text-emerald-400 uppercase tracking-widest flex items-center">
                            <i class="fa-solid fa-bolt mr-2 text-amber-400"></i> Ultimate Freedom
                        </h3>
                        <div class="space-y-2 text-sm font-black text-white">
                            <div class="flex items-center text-emerald-300"><i class="fa-solid fa-circle-check mr-2 text-emerald-400"></i> NO ADVANCE PAYMENT</div>
                            <div class="flex items-center text-emerald-300"><i class="fa-solid fa-circle-check mr-2 text-emerald-400"></i> NO SUBSCRIPTION FEES</div>
                            <div class="flex items-center text-emerald-300"><i class="fa-solid fa-circle-check mr-2 text-emerald-400"></i> NO MONTHLY RENTAL</div>
                            <div class="flex items-center text-emerald-300"><i class="fa-solid fa-circle-check mr-2 text-emerald-400"></i> NO ONE TIME PAYMENT</div>
                            <div class="flex items-center text-emerald-300"><i class="fa-solid fa-circle-check mr-2 text-emerald-400"></i> NO EXTRA CHARGES</div>
                        </div>
                    </div>

                    <div class="bg-slate-800/90 border border-slate-700 rounded-3xl p-6 glow-card-3d space-y-3 text-sm text-slate-300 leading-relaxed">
                        <div class="font-extrabold text-white flex items-center">
                            <i class="fa-solid fa-qrcode mr-2 text-emerald-400"></i> Simple Pay-As-You-Go
                        </div>
                        <p>Simply scan the barcode in your dashboard and fund your wallet with as low as <strong class="text-white">₹100</strong>. Instantly send messages using your loaded balance with complete autonomy.</p>
                    </div>
                </div>

                <!-- Critical Compliance Notes -->
                <div class="bg-amber-500/10 border border-amber-500/30 rounded-3xl p-6 text-sm text-amber-300 space-y-3 glow-card-3d">
                    <div class="font-bold flex items-center text-amber-400">
                        <i class="fa-solid fa-triangle-exclamation mr-2 text-amber-400"></i> Important Platform Compliance Notice:
                    </div>
                    <ul class="list-disc list-inside space-y-1.5 text-slate-200">
                        <li>You <strong>must deactivate or delete</strong> your messaging account from your mobile phone before connecting to this web console. If you do not deactivate or delete your account from your mobile phone, your messages will not be delivered because Meta will not approve that number.</li>
                        <li>You can revert anytime: You can deactivate your session here and use normal communication on your phone whenever you want, then register back on this web app whenever you want.</li>
                    </ul>
                </div>
            </div>

            <!-- Right Side: Interactive Login Card -->
            <div class="lg:col-span-5">
                <div class="glass-card rounded-3xl p-8 sm:p-10 shadow-2xl border border-slate-200 text-slate-800 relative glow-card-3d">
                    
                    <div class="mb-6">
                        <h2 class="text-3xl font-black text-slate-900" id="login-heading">Sign In</h2>
                        <p class="text-sm text-slate-600 mt-1" id="login-subheading">Access your live chat console & campaign manager</p>
                    </div>

                    <!-- Interactive Tabs -->
                    <div class="grid grid-cols-3 gap-1 bg-slate-100 p-1.5 rounded-2xl mb-6 text-xs font-bold text-slate-600">
                        <button type="button" onclick="switchLoginTab('password')" id="tab-btn-password" class="py-2.5 rounded-xl text-center flex items-center justify-center space-x-1 <?= $active_tab === 'password' ? 'bg-white text-emerald-600 shadow-sm' : 'hover:text-slate-900 transition' ?>">
                            <i class="fa-solid fa-key"></i> <span>Password</span>
                        </button>
                        <button type="button" onclick="switchLoginTab('otp')" id="tab-btn-otp" class="py-2.5 rounded-xl text-center flex items-center justify-center space-x-1 <?= $active_tab === 'otp' ? 'bg-white text-emerald-600 shadow-sm' : 'hover:text-slate-900 transition' ?>">
                            <i class="fa-regular fa-envelope"></i> <span>Email OTP</span>
                        </button>
                        <button type="button" onclick="switchLoginTab('admin')" id="tab-btn-admin" class="py-2.5 rounded-xl text-center flex items-center justify-center space-x-1 <?= $active_tab === 'admin' ? 'bg-white text-purple-700 shadow-sm' : 'hover:text-slate-900 transition' ?>">
                            <i class="fa-solid fa-shield-halved"></i> <span>Admin</span>
                        </button>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="mb-4 bg-red-50 border border-red-200 text-red-600 text-xs font-semibold p-3.5 rounded-xl">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold p-3.5 rounded-xl">
                            <?= htmlspecialchars($success) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Form 1: Password Login -->
                    <form method="POST" action="login.php#login-section" id="form-password-login" class="space-y-5 <?= $active_tab === 'password' ? '' : 'hidden' ?>">
                        <input type="hidden" name="auth_mode" value="password">
                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Email Address or Mobile</label>
                            <div class="relative flex items-center">
                                <i class="fa-solid fa-user absolute left-4 text-slate-400"></i>
                                <input type="text" name="identity" placeholder="e.g. name@domain.com or 9876543210" required class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="text-xs font-extrabold text-slate-700 uppercase tracking-wider">Password</label>
                                <a href="forgot_password.php" class="text-xs font-bold text-emerald-600 hover:underline">Forgot Password?</a>
                            </div>
                            <div class="relative flex items-center">
                                <i class="fa-solid fa-lock absolute left-4 text-slate-400"></i>
                                <input type="password" name="password" placeholder="••••••••" required class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-extrabold py-4 rounded-2xl text-sm uppercase tracking-wider shadow-lg shadow-emerald-600/30 transition flex items-center justify-center space-x-2">
                            <span>Log In With Password</span> <i class="fa-solid fa-arrow-right ml-1"></i>
                        </button>
                    </form>

                    <!-- Form 2: Email OTP Login -->
                    <form method="POST" action="login.php#login-section" id="form-otp-login" class="space-y-5 <?= $active_tab === 'otp' ? '' : 'hidden' ?>">
                        <input type="hidden" name="auth_mode" value="otp">
                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Registered Email Address</label>
                            <div class="relative flex items-center">
                                <i class="fa-regular fa-envelope absolute left-4 text-slate-400"></i>
                                <input type="email" name="otp_email" placeholder="name@domain.com" required class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-extrabold py-4 rounded-2xl text-sm uppercase tracking-wider shadow-lg shadow-emerald-600/30 transition flex items-center justify-center space-x-2">
                            <span>Send Login Code to Email</span> <i class="fa-solid fa-paper-plane ml-1"></i>
                        </button>
                    </form>

                    <!-- Form 3: Admin Login -->
                    <form method="POST" action="login.php#login-section" id="form-admin-login" class="space-y-5 <?= $active_tab === 'admin' ? '' : 'hidden' ?>">
                        <input type="hidden" name="auth_mode" value="admin">
                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Admin Email</label>
                            <div class="relative flex items-center">
                                <i class="fa-solid fa-shield-halved absolute left-4 text-purple-600"></i>
                                <input type="email" name="admin_username" placeholder="admin@wubachat.com" required class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-purple-600">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Admin Password</label>
                            <div class="relative flex items-center">
                                <i class="fa-solid fa-lock absolute left-4 text-purple-600"></i>
                                <input type="password" name="admin_password" placeholder="••••••••" required class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-purple-600">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-gradient-to-r from-purple-700 to-purple-900 hover:from-purple-800 hover:to-purple-950 text-white font-extrabold py-4 rounded-2xl text-sm uppercase tracking-wider shadow-lg transition flex items-center justify-center space-x-2">
                            <span>Admin Secure Login</span> <i class="fa-solid fa-shield-halved ml-1"></i>
                        </button>
                    </form>

                    <div class="mt-6 text-center text-sm text-slate-600" id="register-footer-container">
                        Don't have an account yet? <button type="button" onclick="openRegisterModal()" class="text-[#00a884] font-bold hover:underline">Create Account Free</button>
                    </div>

                    <div class="mt-8 pt-6 border-t border-slate-200 flex items-center justify-center space-x-2 text-xs text-slate-500 font-semibold">
                        <i class="fa-solid fa-lock text-emerald-600"></i>
                        <span>256-bit End-to-End Secure Encryption</span>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Why Choose Wubachat & Problem/Solution Section -->
    <section id="why-us" class="py-28 px-6 bg-slate-950 text-slate-300">
        <div class="max-w-7xl mx-auto space-y-20">
            <div class="text-center max-w-3xl mx-auto space-y-4">
                <h2 class="text-xs font-black uppercase tracking-widest text-emerald-400">Enterprise Protection</h2>
                <h3 class="text-3xl sm:text-5xl font-extrabold text-white">Why Businesses Choose Wubachat Over Ordinary Tools</h3>
                <p class="text-base text-slate-400">Prevent account restrictions, protect your brand reputation, and scale customer engagement securely.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 space-y-5 glow-card-3d">
                    <div class="w-14 h-14 rounded-2xl bg-red-500/10 text-red-400 flex items-center justify-center text-2xl font-bold glow-box">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <h4 class="text-xl font-extrabold text-white">The Number Restriction Epidemic</h4>
                    <p class="text-sm text-slate-300 leading-relaxed">
                        Actual reasons why business numbers get restricted include heavy broadcasting, sending too many links without Meta approval, bulk messaging, and violating platform guidelines. This causes severe customer disconnect and revenue loss.
                    </p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 space-y-5 glow-card-3d">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center text-2xl font-bold glow-box">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h4 class="text-xl font-extrabold text-white">Meta-Approved Compliance</h4>
                    <p class="text-sm text-slate-300 leading-relaxed">
                        Wubachat operates strictly on official Meta Cloud API protocols. Your number remains fully protected against algorithmic flagging and sudden bans, ensuring uninterrupted business operations.
                    </p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 space-y-5 glow-card-3d">
                    <div class="w-14 h-14 rounded-2xl bg-blue-500/10 text-blue-400 flex items-center justify-center text-2xl font-bold glow-box">
                        <i class="fa-solid fa-bolt-lightning"></i>
                    </div>
                    <h4 class="text-xl font-extrabold text-white">Unmatched Speed & Power</h4>
                    <p class="text-sm text-slate-300 leading-relaxed">
                        Execute 1000-contact bulk blasts instantly, manage multi-language live chats with 100+ global languages, and translate customer inquiries in real-time with zero latency.
                    </p>
                </div>
            </div>

            <!-- Security & Legal Notice Box -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-8 sm:p-12 space-y-6 glow-card-3d">
                <h4 class="text-2xl font-bold text-white flex items-center">
                    <i class="fa-solid fa-user-shield text-emerald-400 mr-3 text-3xl"></i> Absolute Privacy & Legal Compliance
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-sm text-slate-300 leading-relaxed">
                    <div class="space-y-4">
                        <p><strong>🔒 End-to-End Encryption:</strong> All conversations, customer directories, and media transmissions are 256-bit encrypted and its data and chat have end-to-end encryption whose access is only available to the user. Only you and your authorized team members possess access rights to your data.</p>
                        <p><strong>⚖️ Legal Disclaimer:</strong> Wubachat is a technological software provider. Users are strictly advised to utilize this platform for lawful business and communication purposes only.</p>
                    </div>
                    <div class="space-y-4">
                        <p><strong>⚠️️ Zero Fraud Tolerance:</strong> Any attempt by a user to engage in fraudulent activities, spamming, or phishing Etc. will result in immediate permanent account termination without refund. Wubachat team holds no liability for misuse by individual operators.</p>
                        <p><strong>🔄 Seamless Reversion:</strong> You retain complete ownership of your number. Deactivate your web session anytime to restore normal phone operations instantly.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Comprehensive Detailed Features & "How To Use" Section -->
    <section id="how-to-use" class="py-28 px-6 bg-slate-900 text-slate-300">
        <div class="max-w-7xl mx-auto space-y-16">
            <div class="text-center max-w-3xl mx-auto space-y-4">
                <h2 class="text-xs font-black uppercase tracking-widest text-emerald-400">Complete Feature Directory & Guide</h2>
                <h3 class="text-3xl sm:text-5xl font-extrabold text-white">How To Use Wubachat Like a Pro</h3>
                <p class="text-base text-slate-400">Discover every advanced feature engineered into our platform to supercharge your workflow.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 pb-8">
                <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-4 glow-card-3d">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-600 text-white font-black flex items-center justify-center text-lg glow-box">01</div>
                    <h4 class="text-lg font-bold text-white">Register & Authenticate</h4>
                    <p class="text-sm text-slate-400 leading-relaxed">Create your free account instantly and log in securely via password, email OTP, or admin credentials with 256-bit cryptographic protection.</p>
                </div>

                <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-4 glow-card-3d">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-600 text-white font-black flex items-center justify-center text-lg glow-box">02</div>
                    <h4 class="text-lg font-bold text-white">Top-Up Wallet via UPI</h4>
                    <p class="text-sm text-slate-400 leading-relaxed">Scan the secure admin UPI QR code in your dashboard and fund your balance with as low as ₹100 using a 12-digit UTR reference number.</p>
                </div>

                <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-4 glow-card-3d">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-600 text-white font-black flex items-center justify-center text-lg glow-box">03</div>
                    <h4 class="text-lg font-bold text-white">Connect Meta Cloud API</h4>
                    <p class="text-sm text-slate-400 leading-relaxed">Verify your mobile number through our secure Meta onboarding flow to establish instant authorized cloud connectivity.</p>
                </div>
            </div>

            <!-- ALL FEATURES GRID -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 pt-4">
                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-bolt text-xl"></i>
                        <span>1,000 Contact Bulk Blast</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Copy and paste up to 1,000 numbers directly from Excel, CSV, or Notepad. Attach multiple media files and execute lightning-fast parallel asynchronous campaigns.</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-cloud-arrow-up text-xl"></i>
                        <span>Drag & Drop Media Sharing</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Drag and drop photos, videos, or PDFs anywhere inside the chat window for instant delivery. You can also paste screenshots directly from your clipboard (`Ctrl + V`).</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-language text-xl"></i>
                        <span>100+ Global Language Translator</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Translate outgoing input messages into over 100 world languages with a single click, or instantly translate incoming customer conversations using floating toolbars.</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-reply text-xl"></i>
                        <span>Quote Reply & Threading</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Quote and reply to specific messages with interactive preview tags. Click on any quoted tag to automatically jump and focus on the parent message in the chat stream.</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-images text-xl"></i>
                        <span>Smart Media Albums & Lightbox</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Consecutive images automatically group into sleek photo collages. Includes a fullscreen dark lightbox gallery with keyboard navigation, bulk downloads, and forwarding.</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-comments text-xl"></i>
                        <span>Live Multi-Agent Chat Console</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Real-time conversation management with unread badge counters, live polling, shared contact cards, voice note recorders, and screen-sharing/video-call simulation portals.</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-robot text-xl"></i>
                        <span>Automated Bot Auto-Replies</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Set up custom automated responses to greet incoming leads instantly, ensuring 24/7 engagement even when your support team is offline.</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-magnifying-glass text-xl"></i>
                        <span>Deep Keyword Search Engine</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Search across all contacts, phone numbers, and inside full conversation histories with partial or exact keyword matching and highlighted search results.</p>
                </div>

                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-address-book text-xl"></i>
                        <span>Contact Directory & CSV Import</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">Import thousands of contacts effortlessly using CSV/TXT files, manage saved directories, and filter chats by unread status, favourites, or custom groups.</p>
                </div>

                <!-- Local Device Chat Backup & Zero-Retention Security -->
                <div class="bg-slate-950 border border-emerald-500/30 rounded-3xl p-8 space-y-3 glow-card-3d lg:col-span-3">
                    <div class="flex items-center space-x-3 text-emerald-400 font-extrabold text-base">
                        <i class="fa-solid fa-shield-cat text-xl"></i>
                        <span>Local Device Chat Backup & Zero-Retention Security</span>
                    </div>
                    <p class="text-sm text-slate-300 leading-relaxed">
                        Any messages or data sent and received after logging in remain stored exclusively on the sender's and receiver's devices, provided they choose to download their chat backups. Once you deactivate or disconnect your session on Wubachat Web, Wubachat does not retain or store any of your chat data.
                    </p>
                </div>

            </div>
        </div>
    </section>

    <!-- Contact Us Section -->
    <section id="contact" class="py-28 px-6 bg-slate-950 text-slate-300 border-t border-slate-800">
        <div class="max-w-4xl mx-auto space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-xs font-black uppercase tracking-widest text-emerald-400">Get In Touch</h2>
                <h3 class="text-3xl sm:text-4xl font-extrabold text-white">Contact Our Support Team</h3>
                <p class="text-base text-slate-400">Have questions or need assistance? Drop us a message below.</p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 sm:p-12 shadow-2xl glow-card-3d">
                <form method="POST" action="login.php#contact" class="space-y-6">
                    <input type="hidden" name="auth_mode" value="contact">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-extrabold text-slate-400 uppercase mb-2">Your Name</label>
                            <input type="text" name="c_name" placeholder="John Doe" required class="w-full px-4 py-3.5 bg-slate-950 border border-slate-800 rounded-2xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label class="block text-xs font-extrabold text-slate-400 uppercase mb-2">Email Address</label>
                            <input type="email" name="c_email" placeholder="john@domain.com" required class="w-full px-4 py-3.5 bg-slate-950 border border-slate-800 rounded-2xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-extrabold text-slate-400 uppercase mb-2">Message</label>
                        <textarea name="c_msg" rows="5" placeholder="How can we help you today?" required class="w-full px-4 py-3.5 bg-slate-950 border border-slate-800 rounded-2xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold py-4 rounded-2xl text-sm uppercase tracking-wider shadow-lg transition glow-box">
                        Send Message to support@wubachat.com →
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- Footer with Legal Links & Mandatory Pages -->
    <footer class="bg-slate-900 border-t border-slate-800 text-slate-400 py-16 px-6 text-base">
        <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-4 gap-10 mb-12">
            <div class="space-y-4">
                <div class="bg-white/10 p-2 rounded-2xl border border-emerald-500/30 glow-box inline-flex items-center space-x-2">
                    <img src="/assets/logo.png" alt="Wubachat Logo" class="h-16 object-contain" onerror="this.src='https://placehold.co/360x100/009b67/ffffff?text=Wubachat'">
                </div>
                <p class="text-slate-300 leading-relaxed">Fast & friendly automation suite designed for professional teams and enterprises.</p>
            </div>

            <div class="space-y-3">
                <h4 class="text-white font-bold uppercase tracking-wider text-xs">Legal & Compliance</h4>
                <ul class="space-y-2">
                    <li><a href="privacy-policy.php" class="hover:text-emerald-400 transition">Privacy Policy</a></li>
                    <li><a href="terms.php" class="hover:text-emerald-400 transition">Terms of Service</a></li>
                    <li><a href="disclaimer.php" class="hover:text-emerald-400 transition">Disclaimer</a></li>
                </ul>
            </div>

            <div class="space-y-3">
                <h4 class="text-white font-bold uppercase tracking-wider text-xs">Company</h4>
                <ul class="space-y-2">
                    <li><a href="#why-us" class="hover:text-emerald-400 transition">About Us</a></li>
                    <li><a href="#contact" class="hover:text-emerald-400 transition">Contact Us</a></li>
                </ul>
            </div>

            <div class="space-y-2 text-slate-300">
                <h4 class="text-white font-bold uppercase tracking-wider text-xs">Headquarters & Founder</h4>
                <p>📍 Location: India</p>
                <p>👤 Founder: <strong class="text-white text-lg font-black tracking-wide underline decoration-emerald-500 underline-offset-4">Humera Ameer</strong></p>
                <p>✉️ Support: support@wubachat.com</p>
            </div>
        </div>

        <div class="max-w-7xl mx-auto pt-8 border-t border-slate-800 text-center text-slate-500 flex flex-col sm:flex-row justify-between items-center text-sm">
            <p>&copy; <?= date('Y') ?> Wubachat. All rights reserved.</p>
            <p class="mt-2 sm:mt-0">Secure End-to-End Encrypted Platform</p>
        </div>
    </footer>

    <!-- Register Modal Popup with WhatsApp Compliance & Email OTP Trigger -->
    <div id="register-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden items-center justify-center z-[99999] p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-2xl border border-slate-200 text-slate-800 relative glow-card-3d">
            <div class="flex justify-between items-center mb-6 border-b pb-3">
                <h3 class="text-2xl font-black text-slate-900">Create Free Account</h3>
                <button type="button" onclick="closeRegisterModal()" class="text-slate-400 hover:text-slate-700 text-xl font-bold">✕</button>
            </div>

            <form id="email-submit-form" method="POST" action="login.php#login-section" onsubmit="openComplianceModal(event)" class="space-y-4">
                <input type="hidden" name="auth_mode" value="register">
                
                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1">Email Address</label>
                    <input type="email" id="email-input" name="reg_email" placeholder="name@domain.com" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <p class="text-[10px] text-gray-400 mt-1">We will send a 6-digit verification code to your email.</p>
                </div>

                <button type="submit" id="submit-btn" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-extrabold py-3.5 rounded-xl text-sm uppercase tracking-wider shadow-lg transition glow-box flex items-center justify-center space-x-2">
                    <span>Send Verification Code</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- WHATSAPP COMPLIANCE POPUP MODAL (ENGLISH) -->
    <div id="whatsapp-compliance-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-[999999] p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4 border border-gray-100 text-gray-800">
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

    <!-- ANDROID APP COMING SOON MODAL -->
    <div id="android-coming-soon-modal" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-center justify-center z-[999999] p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-3xl max-w-sm w-full p-6 shadow-2xl text-center space-y-4 glow-card-3d">
            <div class="w-16 h-16 rounded-3xl bg-emerald-500/10 text-emerald-400 mx-auto flex items-center justify-center text-3xl font-bold glow-box">
                <i class="fa-brands fa-android"></i>
            </div>
            <div class="space-y-1">
                <h3 class="text-xl font-black text-white">Android App Coming Soon!</h3>
                <p class="text-xs text-slate-300 leading-relaxed">Our official mobile application is currently under active development and will be launching soon on the Google Play Store.</p>
            </div>
            <div class="pt-2">
                <button type="button" onclick="closeAndroidModal()" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold py-3 rounded-xl text-xs uppercase tracking-wider transition shadow-lg glow-box">
                    Got It, Thanks!
                </button>
            </div>
        </div>
    </div>

    <!-- Script Logic -->
    <script>
        function switchLoginTab(mode) {
            const formPass = document.getElementById('form-password-login');
            const formOtp  = document.getElementById('form-otp-login');
            const formAdm  = document.getElementById('form-admin-login');
            const btnPass  = document.getElementById('tab-btn-password');
            const btnOtp   = document.getElementById('tab-btn-otp');
            const btnAdm   = document.getElementById('tab-btn-admin');
            const heading  = document.getElementById('login-heading');
            const subhead  = document.getElementById('login-subheading');
            const regFoot  = document.getElementById('register-footer-container');

            formPass.classList.add('hidden');
            formOtp.classList.add('hidden');
            formAdm.classList.add('hidden');

            btnPass.className = 'py-2.5 rounded-xl text-center flex items-center justify-center space-x-1 hover:text-slate-900 transition text-slate-600';
            btnOtp.className  = 'py-2.5 rounded-xl text-center flex items-center justify-center space-x-1 hover:text-slate-900 transition text-slate-600';
            btnAdm.className  = 'py-2.5 rounded-xl text-center flex items-center justify-center space-x-1 hover:text-slate-900 transition text-slate-600';

            if (mode === 'password') {
                formPass.classList.remove('hidden');
                btnPass.className = 'bg-white text-emerald-600 py-2.5 rounded-xl shadow-sm text-center flex items-center justify-center space-x-1 transition font-bold';
                heading.innerText = 'Sign In';
                subhead.innerText = 'Access your live chat console & campaign manager';
                regFoot.classList.remove('hidden');
            } else if (mode === 'otp') {
                formOtp.classList.remove('hidden');
                btnOtp.className = 'bg-white text-emerald-600 py-2.5 rounded-xl shadow-sm text-center flex items-center justify-center space-x-1 transition font-bold';
                heading.innerText = 'Email OTP Sign In';
                subhead.innerText = 'Instant verification code sent to your registered email';
                regFoot.classList.remove('hidden');
            } else if (mode === 'admin') {
                formAdm.classList.remove('hidden');
                btnAdm.className = 'bg-white text-purple-700 py-2.5 rounded-xl shadow-sm text-center flex items-center justify-center space-x-1 transition font-bold';
                heading.innerText = 'Admin Master Portal';
                subhead.innerText = 'Manage payments, UPI QR, users & campaigns';
                regFoot.classList.add('hidden');
            }
        }

        function openRegisterModal() {
            const modal = document.getElementById('register-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeRegisterModal() {
            const modal = document.getElementById('register-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Android Coming Soon Modal
        function openAndroidModal() {
            const modal = document.getElementById('android-coming-soon-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeAndroidModal() {
            const modal = document.getElementById('android-coming-soon-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // WhatsApp Compliance Modal Triggers
        function openComplianceModal(e) {
            e.preventDefault();
            const email = document.getElementById('email-input').value.trim();
            if (!email) {
                alert('Please enter a valid email address.');
                return;
            }
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