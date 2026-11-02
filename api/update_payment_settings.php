<?php
// api/update_payment_settings.php - Dynamic Admin UPI & Bank Account Synchronizer
session_start();
header('Content-Type: application/json');

// Flexible Admin Check: Check multiple common admin session variables
$is_authorized = false;
if (
    (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true) ||
    (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id'])) ||
    (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] == true) ||
    (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ||
    (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1) // Fallback for Super-admin User 1
) {
    $is_authorized = true;
}

if (!$is_authorized) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized Access. Please re-login to admin console.']);
    exit;
}

require_once __DIR__ . '/../config.php';

// Capture inputs (Handling all variations of input field names)
$upi_id      = trim($_POST['upi_id'] ?? ($_POST['primary_upi'] ?? ''));
$payee_name  = trim($_POST['payee_name'] ?? ($_POST['account_holder'] ?? 'Wubachat Payments'));
$bank_name   = trim($_POST['bank_name'] ?? '');
$account_no  = trim($_POST['account_number'] ?? ($_POST['account_no'] ?? ''));
$ifsc_code   = trim($_POST['ifsc_code'] ?? ($_POST['ifsc'] ?? ''));

if (empty($upi_id)) {
    echo json_encode(['status' => false, 'message' => 'UPI ID is required']);
    exit;
}

try {
    // Auto-create table if missing so no manual SQL is required
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_payment_settings` (
            `id` INT PRIMARY KEY DEFAULT 1,
            `upi_id` VARCHAR(100) NOT NULL,
            `payee_name` VARCHAR(150) DEFAULT 'Wubachat Payments',
            `bank_name` VARCHAR(100) NULL,
            `account_number` VARCHAR(50) NULL,
            `ifsc_code` VARCHAR(30) NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $pdo->prepare("
        INSERT INTO admin_payment_settings (id, upi_id, payee_name, bank_name, account_number, ifsc_code) 
        VALUES (1, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            upi_id = VALUES(upi_id),
            payee_name = VALUES(payee_name),
            bank_name = VALUES(bank_name),
            account_number = VALUES(account_number),
            ifsc_code = VALUES(ifsc_code)
    ");
    $stmt->execute([$upi_id, $payee_name, $bank_name, $account_no, $ifsc_code]);

    echo json_encode([
        'status'  => true,
        'message' => 'Payment receiver details updated successfully! All future user payments will credit to this account.'
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
?>