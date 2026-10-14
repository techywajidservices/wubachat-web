<?php
// api/verify_otp.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

// Create PDO Instance from config constants
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'Database connection failed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = trim($_POST['action'] ?? '');

// 1. STAGE 1: SEND OTP
if ($action === 'send_otp') {
    $full_name     = trim($_POST['full_name'] ?? '');
    $business_name = trim($_POST['business_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $password      = $_POST['password'] ?? '';
    $terms         = $_POST['terms_accepted'] ?? '0';

    if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
        echo json_encode(['status' => false, 'message' => 'All required fields must be filled.']);
        exit;
    }

    if (!$terms) {
        echo json_encode(['status' => false, 'message' => 'You must accept the Terms and Disclaimer.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => false, 'message' => 'Please provide a valid email address.']);
        exit;
    }

    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean_phone) === 10) {
        $clean_phone = '91' . $clean_phone;
    }

    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
    $stmt->execute([$email, $clean_phone]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'An account with this Email or Mobile already exists.']);
        exit;
    }

    // Generate Secure 6-Digit OTP
    $email_otp  = (string) random_int(100000, 999999);
    $phone_otp  = (string) random_int(100000, 999999);
    $hashed_pw  = password_hash($password, PASSWORD_BCRYPT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Clear old unverified requests for this contact
    $pdo->prepare("DELETE FROM otp_verifications WHERE email = ? OR phone = ?")->execute([$email, $clean_phone]);

    // Save temporary verification record
    $ins = $pdo->prepare("INSERT INTO otp_verifications (email, phone, email_otp, phone_otp, temp_full_name, temp_business_name, temp_password, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([$email, $clean_phone, $email_otp, $phone_otp, $full_name, $business_name, $hashed_pw, $expires_at]);

    // Dispatch Email OTP
    $server_host = $_SERVER['SERVER_NAME'] ?? 'wubachat.com';
    $subject = "Your Wubachat Verification Code";
    $body = "Hello {$full_name},\n\nWelcome to Wubachat.\n\nYour security verification code is: {$email_otp}\n\nThis code is valid for 10 minutes. Do not share this code with anyone.\n\nRegards,\nWubachat Security Team";
    $headers = "From: Wubachat Security <noreply@" . $server_host . ">\r\n" .
               "Reply-To: support@" . $server_host . "\r\n" .
               "X-Mailer: PHP/" . phpversion();
    @mail($email, $subject, $body, $headers);

    echo json_encode([
        'status' => true,
        'message' => 'Verification code sent to your email successfully.',
        'email' => $email,
        'phone' => $clean_phone
    ]);
    exit;
}

// 2. STAGE 2: VALIDATE OTP & ACTIVATE ACCOUNT
if ($action === 'verify_and_register') {
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email_otp = trim($_POST['email_otp'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email]);
    $record = $stmt->fetch();

    if (!$record) {
        echo json_encode(['status' => false, 'message' => 'Verification code expired or not found. Please request again.']);
        exit;
    }

    // Verify OTP Match
    if ($record['email_otp'] !== $email_otp) {
        echo json_encode(['status' => false, 'message' => 'Invalid OTP code. Please check your email inbox.']);
        exit;
    }

    // Insert Final Verified User
    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("INSERT INTO users (name, email, phone, password, wallet_balance) VALUES (?, ?, ?, ?, 0.00)");
        $ins->execute([
            $record['temp_full_name'],
            $record['email'],
            $record['phone'],
            $record['temp_password']
        ]);
        $new_user_id = $pdo->lastInsertId();

        // Delete used OTP
        $pdo->prepare("DELETE FROM otp_verifications WHERE id = ?")->execute([$record['id']]);

        // Auto login session setup
        $_SESSION['user_id']        = $new_user_id;
        $_SESSION['user_name']      = $record['temp_full_name'];
        $_SESSION['wallet_balance'] = 0.00;

        $pdo->commit();

        echo json_encode(['status' => true, 'message' => 'Account successfully verified and activated!']);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => false, 'message' => 'Registration error: ' . $e->getMessage()]);
        exit;
    }
}
?>