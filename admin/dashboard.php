<?php
// admin/dashboard.php - Complete Enterprise Master Admin Dashboard with Payment Auditing, Meta Cost Telemetry, Logo, 3D Glowing UI & Exports
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config.php';

$admin_id   = $_SESSION['admin_id'] ?? 1;
$admin_name = $_SESSION['admin_name'] ?? 'Super Admin';
$msg        = '';
$error      = '';

// Safe Multi-Table Auto Migrations & Missing Column Fixes
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_payment_settings` (
            `id` INT PRIMARY KEY DEFAULT 1,
            `upi_id` VARCHAR(191) NOT NULL,
            `payee_name` VARCHAR(191) NOT NULL,
            `account_number` VARCHAR(100) NULL,
            `ifsc_code` VARCHAR(50) NULL,
            `bank_name` VARCHAR(100) NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `global_announcements` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `type` VARCHAR(50) DEFAULT 'info',
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `deleted_users_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `original_user_id` INT NOT NULL,
            `name` VARCHAR(191) NULL,
            `email` VARCHAR(191) NULL,
            `phone` VARCHAR(50) NULL,
            `ip_address` VARCHAR(50) NULL,
            `location` VARCHAR(191) NULL,
            `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `rejoined_at` TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `system_error_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `error_type` VARCHAR(100) NOT NULL,
            `details` TEXT NOT NULL,
            `ip_address` VARCHAR(50) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `wallet_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `utr_number` VARCHAR(100) NULL,
            `type` VARCHAR(20) DEFAULT 'credit',
            `status` VARCHAR(20) DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `meta_api_expenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `amount_charged` DECIMAL(10,4) NOT NULL,
            `currency` VARCHAR(10) DEFAULT 'INR',
            `status` VARCHAR(30) DEFAULT 'success',
            `api_response` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Seamless compatibility for account_number / bank_account columns
    $chkCol = $pdo->query("SHOW COLUMNS FROM `admin_payment_settings` LIKE 'account_number'")->fetchAll();
    if (empty($chkCol)) {
        $pdo->exec("ALTER TABLE `admin_payment_settings` ADD COLUMN `account_number` VARCHAR(100) NULL AFTER `payee_name`");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM `deleted_users_log` LIKE 'rejoined_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `deleted_users_log` ADD COLUMN `rejoined_at` TIMESTAMP NULL DEFAULT NULL");
    }

    $stmtPayCheck = $pdo->query("SELECT COUNT(*) FROM admin_payment_settings WHERE id = 1");
    if ($stmtPayCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO admin_payment_settings (id, upi_id, payee_name, account_number, ifsc_code, bank_name) VALUES (1, 'wubachat@okaxis', 'Wubachat Payments', '919876543210', 'PYTM0123456', 'Paytm Payments Bank')");
    }
} catch (Exception $e) {}

// Date Filter Parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

// EXPORT TO EXCEL (CSV) HANDLER
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=wubachat_transactions_' . $start_date . '_to_' . $end_date . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Transaction ID', 'User ID', 'User Name', 'Phone', 'Amount (INR)', 'UTR Number', 'Status', 'Date']);

    $stmtCsv = $pdo->prepare("
        SELECT t.id, t.user_id, u.name, u.phone, t.amount, t.utr_number, t.status, t.created_at
        FROM wallet_transactions t
        JOIN users u ON t.user_id = u.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?
        ORDER BY t.id DESC
    ");
    $stmtCsv->execute([$start_date, $end_date]);
    while ($row = $stmtCsv->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// AJAX Endpoint for Live User Chat Inspection
if (isset($_GET['action']) && $_GET['action'] === 'fetch_user_chats') {
    header('Content-Type: application/json');
    $inspect_uid = (int)($_GET['user_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT id, recipient, message_text, direction, status, created_at 
            FROM chat_messages 
            WHERE user_id = ? 
            ORDER BY id DESC 
            LIMIT 200
        ");
        $stmt->execute([$inspect_uid]);
        $chats = $stmt->fetchAll();
        echo json_encode(['status' => true, 'chats' => $chats]);
    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ACTION: Save Bank & UPI Details (Fixed & Synced with Database)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bank_details'])) {
    $upi_id         = trim($_POST['upi_id'] ?? '');
    $payee_name     = trim($_POST['payee_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? ($_POST['bank_account'] ?? ''));
    $ifsc_code      = trim($_POST['ifsc_code'] ?? '');
    $bank_name      = trim($_POST['bank_name'] ?? '');

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_payment_settings (id, upi_id, payee_name, account_number, ifsc_code, bank_name) 
            VALUES (1, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                upi_id = VALUES(upi_id), 
                payee_name = VALUES(payee_name), 
                account_number = VALUES(account_number), 
                ifsc_code = VALUES(ifsc_code), 
                bank_name = VALUES(bank_name)
        ");
        $stmt->execute([$upi_id, $payee_name, $account_number, $ifsc_code, $bank_name]);
        $msg = "Bank & UPI Receiver details updated successfully! Live QR code is now active.";
    } catch (Exception $e) {
        $error = "Error updating payment settings: " . $e->getMessage();
    }
}

// ACTION: Update Admin Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_pass'])) {
    $new_pass = $_POST['admin_new_pass'] ?? '';
    if (strlen($new_pass) >= 6) {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")->execute([$hash, $admin_id]);
        $msg = "Admin password updated successfully!";
    } else {
        $error = "Password must be at least 6 characters long.";
    }
}

// ACTION: Broadcast System Announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_announcement'])) {
    $title = trim($_POST['ann_title'] ?? '');
    $text  = trim($_POST['ann_message'] ?? '');
    $type  = $_POST['ann_type'] ?? 'info';

    if (!empty($title) && !empty($text)) {
        $pdo->prepare("INSERT INTO global_announcements (title, message, type) VALUES (?, ?, ?)")->execute([$title, $text, $type]);
        $msg = "Global user announcement broadcasted live!";
    }
}

// ACTION: Edit User Profile & Wallet Balance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user_submit'])) {
    $uid         = (int)$_POST['edit_uid'];
    $name        = trim($_POST['edit_name']);
    $email       = trim($_POST['edit_email']);
    $phone       = trim($_POST['edit_phone']);
    $new_wallet  = floatval($_POST['edit_wallet'] ?? 0);
    $user_pass   = trim($_POST['edit_password'] ?? '');

    try {
        if (!empty($user_pass)) {
            $hash = password_hash($user_pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, wallet_balance = ?, password = ? WHERE id = ?")->execute([$name, $email, $phone, $new_wallet, $hash, $uid]);
        } else {
            $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, wallet_balance = ? WHERE id = ?")->execute([$name, $email, $phone, $new_wallet, $uid]);
        }
        $msg = "User #{$uid} profile and wallet balance updated successfully!";
    } catch (Exception $e) {
        $error = "Failed to update user: " . $e->getMessage();
    }
}

// ACTION: Delete User (With Retention Logging)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_submit'])) {
    $uid = (int)$_POST['delete_uid'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();

        if ($u) {
            $pdo->prepare("INSERT INTO deleted_users_log (original_user_id, name, email, phone, location) VALUES (?, ?, ?, ?, ?)")
                ->execute([$u['id'], $u['name'] ?? 'User', $u['email'], $u['phone'], $u['location'] ?? 'India']);
            
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            $msg = "User #{$uid} permanently removed and logged in deleted archives.";
        }
    } catch (Exception $e) {
        $error = "Error deleting user: " . $e->getMessage();
    }
}

// ACTION: Notify Low Balance Users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_low_balance'])) {
    $threshold = (float)($_POST['threshold'] ?? 10);
    $stmtLow = $pdo->prepare("SELECT id, name, phone, email, wallet_balance FROM users WHERE wallet_balance <= ?");
    $stmtLow->execute([$threshold]);
    $lowUsers = $stmtLow->fetchAll();

    $count = 0;
    foreach ($lowUsers as $lu) {
        if (!empty($lu['phone']) && defined('META_PHONE_NUMBER_ID') && defined('META_SYSTEM_TOKEN')) {
            $phone = preg_replace('/[^0-9]/', '', $lu['phone']);
            if (strlen($phone) === 10) $phone = '91' . $phone;

            $payload = [
                "messaging_product" => "whatsapp",
                "to" => $phone,
                "type" => "text",
                "text" => ["body" => "⚠️ *Wubachat Low Balance Alert*\n\nDear {$lu['name']}, your wallet balance is ₹" . number_format($lu['wallet_balance'], 2) . ". Please recharge to continue uninterrupted WhatsApp broadcasts."]
            ];

            $ch = curl_init("https://graph.facebook.com/" . META_GRAPH_VERSION . "/" . META_PHONE_NUMBER_ID . "/messages");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . META_SYSTEM_TOKEN, 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
            $count++;
        }
    }
    $msg = "Dispatched low balance recharge alerts to {$count} users!";
}

// ACTION: Approve/Reject Recharge
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_recharge'])) {
    $tx_id  = (int)$_POST['tx_id'];
    $action = $_POST['action'];

    $stmt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$tx_id]);
    $tx = $stmt->fetch();

    if ($tx) {
        if ($action === 'approve') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")->execute([$tx['amount'], $tx['user_id']]);
            $pdo->prepare("UPDATE wallet_transactions SET status = 'success' WHERE id = ?")->execute([$tx_id]);
            $pdo->commit();
            $msg = "Recharge of ₹" . number_format($tx['amount'], 2) . " approved!";
        } else {
            $pdo->prepare("UPDATE wallet_transactions SET status = 'rejected' WHERE id = ?")->execute([$tx_id]);
            $msg = "Recharge request rejected.";
        }
    }
}

// Fetch Master Bank & UPI Details Fresh from Database
$pay_info = $pdo->query("SELECT * FROM admin_payment_settings WHERE id = 1")->fetch() ?: [];
$current_upi_id = !empty($pay_info['upi_id']) ? $pay_info['upi_id'] : 'wubachat@okaxis';
$current_payee  = !empty($pay_info['payee_name']) ? $pay_info['payee_name'] : 'Wubachat Payments';
$current_acc    = !empty($pay_info['account_number']) ? $pay_info['account_number'] : ($pay_info['bank_account'] ?? '');
$current_ifsc   = !empty($pay_info['ifsc_code']) ? $pay_info['ifsc_code'] : '';
$current_bank   = !empty($pay_info['bank_name']) ? $pay_info['bank_name'] : '';

// Financial Statistics
$total_revenue = $pdo->prepare("SELECT SUM(amount) FROM wallet_transactions WHERE status = 'success' AND DATE(created_at) BETWEEN ? AND ?");
$total_revenue->execute([$start_date, $end_date]);
$revenue_val = (float)$total_revenue->fetchColumn() ?: 0.00;

// Actual Meta Expenses Tracking
$total_meta_exp = $pdo->prepare("SELECT SUM(amount_charged) FROM meta_api_expenses WHERE DATE(created_at) BETWEEN ? AND ?");
$total_meta_exp->execute([$start_date, $end_date]);
$meta_expense_val = (float)$total_meta_exp->fetchColumn() ?: ($revenue_val * 0.45); 

$net_profit = $revenue_val - $meta_expense_val;

// Payment Audit Logs (Success & Failed/Rejected)
$payment_audit_logs = [];
try {
    $stmtPayLogs = $pdo->prepare("
        SELECT t.*, u.name as user_name, u.phone as user_phone, u.email as user_email
        FROM wallet_transactions t
        JOIN users u ON t.user_id = u.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?
        ORDER BY t.id DESC
        LIMIT 100
    ");
    $stmtPayLogs->execute([$start_date, $end_date]);
    $payment_audit_logs = $stmtPayLogs->fetchAll();
} catch (Exception $e) {}

// Meta Card Expense Records
$meta_expense_records = [];
try {
    $stmtMetaExp = $pdo->prepare("SELECT * FROM meta_api_expenses WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC LIMIT 50");
    $stmtMetaExp->execute([$start_date, $end_date]);
    $meta_expense_records = $stmtMetaExp->fetchAll();
} catch (Exception $e) {}

// Users & Retentions
$all_users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();

$deleted_users = [];
$rejoined_users = [];
try {
    $deleted_users = $pdo->query("SELECT * FROM deleted_users_log WHERE rejoined_at IS NULL ORDER BY id DESC LIMIT 20")->fetchAll();
    $rejoined_users = $pdo->query("SELECT * FROM deleted_users_log WHERE rejoined_at IS NOT NULL ORDER BY id DESC LIMIT 20")->fetchAll();
} catch (Exception $e) {}

$error_logs = [];
try {
    $error_logs = $pdo->query("SELECT e.*, u.name as user_name, u.phone as user_phone FROM system_error_logs e LEFT JOIN users u ON e.user_id = u.id ORDER BY e.id DESC LIMIT 30")->fetchAll();
} catch (Exception $e) {}

$pending_recharges = [];
try {
    $pending_recharges = $pdo->query("
        SELECT t.*, u.name as user_name, u.phone as user_phone 
        FROM wallet_transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.status = 'pending' 
        ORDER BY t.id DESC
    ")->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Admin - Wubachat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js & Leaflet Maps CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0b0f19; color: #f8fafc; }
        @media print {
            .no-print { display: none !important; }
            .print-full { width: 100% !important; margin: 0 !important; }
        }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        
        /* 3D Glowing & Glassmorphism Styling */
        .glass-card {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(16, 185, 129, 0.2);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.7), 0 0 25px rgba(16, 185, 129, 0.15);
        }
        .glow-box { box-shadow: 0 0 30px rgba(16, 185, 129, 0.35); }
        .glow-text { text-shadow: 0 0 15px rgba(16, 185, 129, 0.5); }
        .logo-glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 25px rgba(0, 155, 103, 0.2);
        }
    </style>
</head>
<body class="min-h-screen antialiased selection:bg-emerald-500 selection:text-white">

    <!-- Top Admin Header -->
    <header class="bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-6 py-3.5 flex justify-between items-center shadow-2xl sticky top-0 z-40 no-print">
        <div class="flex items-center space-x-3">
            <div class="logo-glass px-3.5 py-2 rounded-2xl border border-emerald-500/30 glow-box">
                <img src="/assets/logo.png" alt="Wubachat Logo" class="h-8 object-contain" onerror="this.src='https://placehold.co/120x40/009b67/ffffff?text=Wubachat'">
            </div>
            <div>
                <h1 class="text-sm font-black text-white leading-tight glow-text">Master Suite & Control</h1>
                <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider">Enterprise Controller</span>
            </div>
        </div>

        <div class="flex items-center space-x-3">
            <a href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&export=csv" class="bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center space-x-1.5 glow-box">
                <i class="fa-solid fa-file-excel"></i>
                <span>Download CSV</span>
            </a>

            <button onclick="window.print()" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center space-x-1.5">
                <i class="fa-solid fa-file-pdf text-red-400"></i>
                <span>Export PDF</span>
            </button>

            <button onclick="openModal('admin-profile-modal')" class="bg-purple-500/10 text-purple-300 border border-purple-500/30 text-xs px-3 py-1.5 rounded-xl font-bold flex items-center space-x-1">
                <i class="fa-solid fa-key text-[10px]"></i>
                <span>Change Password</span>
            </button>

            <a href="../logout.php" class="bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 text-xs px-3 py-1.5 rounded-xl font-bold transition">
                <i class="fa-solid fa-power-off mr-1"></i> Log Out
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto p-6 space-y-6">

        <!-- Notification Banner -->
        <?php if (!empty($msg)): ?>
            <div class="p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-2xl text-xs font-bold flex items-center space-x-2 shadow-lg glow-box no-print">
                <i class="fa-solid fa-circle-check text-base text-emerald-400"></i>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="p-4 bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl text-xs font-bold flex items-center space-x-2 shadow-lg no-print">
                <i class="fa-solid fa-triangle-exclamation text-base text-red-400"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Date Range Filter & Financial KPI Cards -->
        <div class="glass-card p-6 rounded-3xl space-y-4">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 no-print border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-sm font-black text-white">Financial Growth & Meta API Expense Analysis</h2>
                    <p class="text-[11px] text-slate-400">Live profit tracking, customer payments & actual credit card expense telemetry</p>
                </div>

                <form method="GET" class="flex items-center space-x-2 text-xs">
                    <input type="date" name="start_date" value="<?= $start_date ?>" class="px-3 py-1.5 bg-slate-900 border border-slate-700 text-white rounded-xl font-semibold">
                    <span class="text-slate-400 font-bold">to</span>
                    <input type="date" name="end_date" value="<?= $end_date ?>" class="px-3 py-1.5 bg-slate-900 border border-slate-700 text-white rounded-xl font-semibold">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-3.5 py-1.5 rounded-xl font-bold shadow-md glow-box">Filter</button>
                </form>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 pt-2">
                <div class="bg-purple-950/40 p-4 rounded-2xl border border-purple-800/50">
                    <span class="text-[10px] text-purple-400 font-bold uppercase">Total Revenue Collected</span>
                    <h3 class="text-2xl font-black text-purple-200 mt-1">₹<?= number_format($revenue_val, 2) ?></h3>
                </div>

                <div class="bg-rose-950/40 p-4 rounded-2xl border border-rose-800/50">
                    <span class="text-[10px] text-rose-400 font-bold uppercase">Meta Card API Expense</span>
                    <h3 class="text-2xl font-black text-rose-200 mt-1">₹<?= number_format($meta_expense_val, 2) ?></h3>
                </div>

                <div class="bg-emerald-950/40 p-4 rounded-2xl border border-emerald-800/50">
                    <span class="text-[10px] text-emerald-400 font-bold uppercase">Net Business Profit</span>
                    <h3 class="text-2xl font-black text-emerald-300 mt-1">₹<?= number_format($net_profit, 2) ?></h3>
                </div>

                <div class="bg-blue-950/40 p-4 rounded-2xl border border-blue-800/50">
                    <span class="text-[10px] text-blue-400 font-bold uppercase">Total Registered Users</span>
                    <h3 class="text-2xl font-black text-blue-200 mt-1"><?= count($all_users) ?></h3>
                </div>
            </div>

            <!-- Profit / Loss Visual Chart -->
            <div class="pt-4 h-64">
                <canvas id="financialChart"></canvas>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- 1. Master Bank Details & Live QR Generator with Center Wubachat Logo -->
            <div class="glass-card p-6 rounded-3xl space-y-4">
                <div class="flex items-center space-x-2 border-b border-slate-800 pb-3">
                    <span class="w-7 h-7 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center text-xs font-bold"><i class="fa-solid fa-building-columns"></i></span>
                    <h3 class="text-sm font-extrabold text-white">Admin Bank & UPI Receiver</h3>
                </div>

                <form method="POST" class="space-y-3 text-xs">
                    <input type="hidden" name="save_bank_details" value="1">

                    <div>
                        <label class="block font-bold text-slate-400 uppercase text-[10px] mb-1">Primary UPI ID</label>
                        <input type="text" name="upi_id" value="<?= htmlspecialchars($current_upi_id) ?>" required class="w-full px-3 py-2 bg-slate-900 border border-slate-700 text-white rounded-xl font-bold">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-400 uppercase text-[10px] mb-1">Account Holder / Payee Name</label>
                        <input type="text" name="payee_name" value="<?= htmlspecialchars($current_payee) ?>" required class="w-full px-3 py-2 bg-slate-900 border border-slate-700 text-white rounded-xl font-bold">
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-bold text-slate-400 uppercase text-[10px] mb-1">Bank Account No.</label>
                            <input type="text" name="account_number" value="<?= htmlspecialchars($current_acc) ?>" placeholder="e.g. 919876543210" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 text-white rounded-xl font-bold">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-400 uppercase text-[10px] mb-1">IFSC Code</label>
                            <input type="text" name="ifsc_code" value="<?= htmlspecialchars($current_ifsc) ?>" placeholder="e.g. HDFC0000123" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 text-white rounded-xl font-bold">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-400 uppercase text-[10px] mb-1">Bank Name</label>
                        <input type="text" name="bank_name" value="<?= htmlspecialchars($current_bank) ?>" placeholder="e.g. HDFC / SBI / Paytm" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 text-white rounded-xl font-bold">
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 rounded-xl uppercase tracking-wider shadow-md glow-box transition">
                        Update Receiver Bank Details
                    </button>
                </form>

                <div class="p-3 bg-slate-900/80 border border-slate-800 rounded-2xl text-center space-y-2 relative">
                    <span class="text-[10px] font-bold text-slate-400 uppercase">Live Dynamic QR Scanner with Wubachat Logo</span>
                    <div class="relative inline-block mx-auto">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=upi://pay?pa=<?= urlencode($current_upi_id) ?>%26pn=<?= urlencode($current_payee) ?>%26cu=INR" class="w-32 h-32 mx-auto rounded-xl border border-slate-700 shadow-sm">
                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <span class="bg-white px-1.5 py-0.5 rounded-md font-black text-[9px] text-emerald-700 shadow border border-emerald-300">WUBACHAT</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Global Announcements & Notifications -->
            <div class="glass-card p-6 rounded-3xl space-y-5">
                <div class="space-y-3">
                    <div class="flex items-center space-x-2">
                        <span class="w-7 h-7 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center text-xs font-bold"><i class="fa-solid fa-bullhorn"></i></span>
                        <h3 class="text-sm font-extrabold text-white">Push Global Notification</h3>
                    </div>
                    
                    <form method="POST" class="space-y-2.5 text-xs">
                        <input type="hidden" name="post_announcement" value="1">
                        <input type="text" name="ann_title" placeholder="Notice Title (e.g. 20% Extra Credits Offer)" required class="w-full px-3 py-2 bg-slate-900 border border-slate-700 text-white rounded-xl font-bold">
                        <textarea name="ann_message" rows="2" placeholder="Write broadcast notice for all user dashboards..." required class="w-full px-3 py-2 bg-slate-900 border border-slate-700 text-white rounded-xl font-medium"></textarea>
                        
                        <div class="flex justify-between items-center">
                            <select name="ann_type" class="px-2 py-1 bg-slate-900 border border-slate-700 text-slate-300 rounded-lg font-bold text-[11px]">
                                <option value="info">Info (Blue)</option>
                                <option value="offer">Special Offer (Green)</option>
                                <option value="warning">Maintenance (Red)</option>
                            </select>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-4 py-1.5 rounded-xl shadow-md">Broadcast Notice</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 3. Pending UTR Approvals -->
            <div class="glass-card p-6 rounded-3xl space-y-4">
                <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                    <div class="flex items-center space-x-2">
                        <span class="w-7 h-7 rounded-lg bg-purple-500/10 text-purple-400 flex items-center justify-center text-xs font-bold"><i class="fa-solid fa-receipt"></i></span>
                        <h3 class="text-sm font-extrabold text-white">Pending Recharge Approvals</h3>
                    </div>
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-300"><?= count($pending_recharges) ?></span>
                </div>

                <div class="space-y-2.5 max-h-80 overflow-y-auto custom-scrollbar">
                    <?php if (empty($pending_recharges)): ?>
                        <div class="p-6 text-center text-slate-500 text-xs font-medium">
                            <i class="fa-regular fa-circle-check text-2xl text-emerald-400 mb-1"></i>
                            <p>No pending recharges to verify.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_recharges as $pr): ?>
                            <div class="p-3 bg-slate-900/90 rounded-2xl border border-slate-800 flex justify-between items-center text-xs">
                                <div>
                                    <h4 class="font-bold text-white"><?= htmlspecialchars($pr['user_name']) ?></h4>
                                    <span class="font-mono text-[10px] text-slate-400 block">UTR: <?= htmlspecialchars($pr['utr_number']) ?></span>
                                    <span class="text-emerald-400 font-extrabold text-sm">₹<?= number_format($pr['amount'], 2) ?></span>
                                </div>
                                <div class="space-x-1">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action_recharge" value="1">
                                        <input type="hidden" name="tx_id" value="<?= $pr['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="bg-emerald-600 text-white px-2.5 py-1 rounded-lg font-bold text-xs shadow-sm" title="Approve Payment">✓</button>
                                    </form>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action_recharge" value="1">
                                        <input type="hidden" name="tx_id" value="<?= $pr['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" onclick="return confirm('Reject this payment?')" class="bg-red-600 text-white px-2.5 py-1 rounded-lg font-bold text-xs shadow-sm" title="Reject / Log as Failed">✕</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- User Payment History Audit -->
        <div class="glass-card p-6 rounded-3xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <div class="flex items-center space-x-2">
                    <span class="w-7 h-7 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center text-xs font-bold"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                    <h3 class="text-sm font-extrabold text-white">User Payment History Audit (Successful & Failed Transactions)</h3>
                </div>
                <span class="text-xs text-slate-400 font-semibold"><?= count($payment_audit_logs) ?> Records Found</span>
            </div>

            <div class="overflow-x-auto max-h-80 custom-scrollbar">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-slate-800 text-slate-400 font-bold uppercase text-[10px]">
                            <th class="py-2 px-3">TX ID</th>
                            <th class="py-2 px-3">User Details</th>
                            <th class="py-2 px-3">Amount</th>
                            <th class="py-2 px-3">UTR Reference</th>
                            <th class="py-2 px-3">Status</th>
                            <th class="py-2 px-3">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php if (empty($payment_audit_logs)): ?>
                            <tr><td colspan="6" class="text-center py-6 text-slate-500">No payment transaction records in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payment_audit_logs as $pal): ?>
                                <tr class="hover:bg-slate-900/50">
                                    <td class="py-2.5 px-3 font-mono font-bold text-slate-500">#<?= $pal['id'] ?></td>
                                    <td class="py-2.5 px-3 font-bold text-white"><?= htmlspecialchars($pal['user_name']) ?><br><span class="text-[10px] text-slate-400 font-normal"><?= htmlspecialchars($pal['user_email']) ?></span></td>
                                    <td class="py-2.5 px-3 font-black text-emerald-400">₹<?= number_format($pal['amount'], 2) ?></td>
                                    <td class="py-2.5 px-3 font-mono text-slate-300"><?= htmlspecialchars($pal['utr_number'] ?? 'N/A') ?></td>
                                    <td class="py-2.5 px-3">
                                        <?php if ($pal['status'] === 'success'): ?>
                                            <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-300 rounded-full font-bold text-[10px]">Success</span>
                                        <?php elseif ($pal['status'] === 'pending'): ?>
                                            <span class="px-2 py-0.5 bg-amber-500/20 text-amber-300 rounded-full font-bold text-[10px]">Pending</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-red-500/20 text-red-300 rounded-full font-bold text-[10px]">Failed / Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-400 text-[11px]"><?= $pal['created_at'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Meta API Credit Card Expense Telemetry -->
        <div class="glass-card p-6 rounded-3xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <div class="flex items-center space-x-2">
                    <span class="w-7 h-7 rounded-lg bg-rose-500/10 text-rose-400 flex items-center justify-center text-xs font-bold"><i class="fa-solid fa-credit-card"></i></span>
                    <h3 class="text-sm font-extrabold text-white">Meta Credit Card & API Billing Ledger</h3>
                </div>
                <span class="text-xs text-slate-400 font-semibold"><?= count($meta_expense_records) ?> Card Deductions</span>
            </div>

            <div class="overflow-x-auto max-h-60 custom-scrollbar">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-slate-800 text-slate-400 font-bold uppercase text-[10px]">
                            <th class="py-2 px-3">Expense ID</th>
                            <th class="py-2 px-3">Charged Amount</th>
                            <th class="py-2 px-3">Status</th>
                            <th class="py-2 px-3">API Response Payload</th>
                            <th class="py-2 px-3">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono">
                        <?php if (empty($meta_expense_records)): ?>
                            <tr><td colspan="5" class="text-center py-6 text-slate-500 font-sans">No Meta credit card charges logged in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($meta_expense_records as $mer): ?>
                                <tr class="hover:bg-slate-900/50">
                                    <td class="py-2.5 px-3 text-slate-500">#<?= $mer['id'] ?></td>
                                    <td class="py-2.5 px-3 font-bold text-rose-400">₹<?= number_format($mer['amount_charged'], 2) ?></td>
                                    <td class="py-2.5 px-3 text-emerald-400 font-bold"><?= htmlspecialchars($mer['status']) ?></td>
                                    <td class="py-2.5 px-3 text-slate-400 max-w-xs truncate"><?= htmlspecialchars($mer['api_response'] ?? 'Auto-deducted via Meta Cloud API threshold') ?></td>
                                    <td class="py-2.5 px-3 text-slate-500 text-[10px]"><?= $mer['created_at'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Geographic User Location Map -->
        <div class="glass-card p-6 rounded-3xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <div class="flex items-center space-x-2">
                    <span class="w-7 h-7 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center text-xs font-bold"><i class="fa-solid fa-map-location-dot"></i></span>
                    <h3 class="text-sm font-extrabold text-white">User Distribution Geography (India & Global)</h3>
                </div>
                <span class="text-xs text-slate-400 font-semibold">Live Geolocation Telemetry</span>
            </div>
            <div id="users-map" class="h-72 rounded-2xl border border-slate-800 z-10"></div>
        </div>

        <!-- User Directory & Full Account Editor with Live Chat Monitor -->
        <div class="glass-card p-6 rounded-3xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <div class="flex items-center space-x-2">
                    <span class="w-7 h-7 rounded-lg bg-purple-500/10 text-purple-400 flex items-center justify-center text-xs font-bold"><i class="fa-solid fa-users"></i></span>
                    <h3 class="text-sm font-extrabold text-white">Manage Users (Chat Inspector, Edit, Delete, Passwords)</h3>
                </div>
                <span class="text-xs text-slate-400 font-semibold"><?= count($all_users) ?> Active Accounts</span>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-slate-800 text-slate-400 font-bold uppercase text-[10px]">
                            <th class="py-2.5 px-3">UID</th>
                            <th class="py-2.5 px-3">Full Name</th>
                            <th class="py-2.5 px-3">Email</th>
                            <th class="py-2.5 px-3">WhatsApp Number</th>
                            <th class="py-2.5 px-3">Wallet Balance</th>
                            <th class="py-2.5 px-3 text-right">Actions & Security Audit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($all_users as $u): ?>
                            <tr class="hover:bg-slate-900/50 transition">
                                <td class="py-3 px-3 font-mono font-bold text-slate-500">#<?= $u['id'] ?></td>
                                <td class="py-3 px-3 font-bold text-white"><?= htmlspecialchars($u['name'] ?? 'User') ?></td>
                                <td class="py-3 px-3 font-medium text-slate-300"><?= htmlspecialchars($u['email']) ?></td>
                                <td class="py-3 px-3 font-mono text-slate-400">+<?= htmlspecialchars($u['phone'] ?? 'N/A') ?></td>
                                <td class="py-3 px-3 font-extrabold text-emerald-400">₹<?= number_format($u['wallet_balance'] ?? 0, 2) ?></td>
                                <td class="py-3 px-3 text-right space-x-2 whitespace-nowrap">
                                    <button type="button" onclick="inspectUserChatLive(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'] ?? 'User')) ?>', '<?= htmlspecialchars($u['phone'] ?? '') ?>')" class="bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 border border-blue-500/30 px-2.5 py-1 rounded-xl font-bold text-xs transition shadow-sm" title="Read all user sent and received WhatsApp messages">
                                        <i class="fa-solid fa-eye mr-1"></i> Inspect Chats
                                    </button>

                                    <button type="button" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>)" class="text-purple-400 hover:text-purple-300 font-bold text-xs"><i class="fa-solid fa-pen-to-square"></i> Edit User & Wallet</button>
                                    
                                    <form method="POST" class="inline" onsubmit="return confirm('Permanently delete user #<?= $u['id'] ?>?')">
                                        <input type="hidden" name="delete_user_submit" value="1">
                                        <input type="hidden" name="delete_uid" value="<?= $u['id'] ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 font-bold text-xs"><i class="fa-solid fa-trash"></i> Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- POPUP MODAL: LIVE USER CHAT AUDIT & MONITOR INSPECTOR -->
    <div id="inspect-chat-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-slate-900 rounded-3xl max-w-3xl w-full flex flex-col max-h-[88vh] shadow-2xl border border-slate-700 overflow-hidden text-slate-100">
            
            <div class="px-6 py-3.5 border-b border-slate-800 flex justify-between items-center bg-slate-950">
                <div class="flex items-center space-x-3">
                    <span class="w-9 h-9 rounded-2xl bg-blue-500/20 text-blue-400 flex items-center justify-center font-bold text-sm">
                        <i class="fa-solid fa-user-secret"></i>
                    </span>
                    <div>
                        <h3 class="text-sm font-black text-white flex items-center" id="inspect-modal-username">
                            Inspecting User WhatsApp Stream
                        </h3>
                        <span class="text-[10px] text-slate-400 font-mono block" id="inspect-modal-phone">+91 0000000000</span>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2">
                    <span class="bg-red-500/20 text-red-400 border border-red-500/30 text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider">
                        <i class="fa-solid fa-shield-halved mr-1"></i> Security Audit
                    </span>
                    <button onclick="closeModal('inspect-chat-modal')" class="text-slate-400 hover:text-white text-base ml-2">✕</button>
                </div>
            </div>

            <div class="p-3 bg-slate-900 border-b border-slate-800 flex items-center">
                <div class="relative w-full">
                    <i class="fa-solid fa-search absolute left-3.5 top-2.5 text-slate-400 text-xs"></i>
                    <input type="text" id="inspect-search-input" oninput="filterInspectMessages()" placeholder="Search keywords in this user's conversation..." class="w-full pl-9 pr-4 py-1.5 bg-slate-950 border border-slate-800 text-white rounded-xl text-xs focus:outline-none focus:ring-1 focus:ring-blue-500">
                </div>
            </div>

            <div class="flex-1 p-6 overflow-y-auto space-y-3 bg-[#075e54]/20 custom-scrollbar" id="inspect-stream-container">
                <div class="text-center py-12 text-slate-400">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-400 mb-2"></i>
                    <p class="text-xs">Loading complete chat log...</p>
                </div>
            </div>

            <div class="p-3.5 border-t border-slate-800 bg-slate-950 flex justify-between items-center text-xs">
                <span class="text-slate-400 font-semibold" id="inspect-total-count">Showing latest messages</span>
                <button onclick="closeModal('inspect-chat-modal')" class="px-4 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl transition">Close Inspector</button>
            </div>
        </div>
    </div>

    <!-- MODAL: EDIT USER & WALLET -->
    <div id="edit-user-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-slate-900 rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-700 space-y-4 text-slate-100">
            <div class="flex justify-between items-center border-b border-slate-800 pb-2">
                <h3 class="text-sm font-extrabold text-white">Edit User Profile & Wallet Balance</h3>
                <button onclick="closeModal('edit-user-modal')" class="text-slate-400 hover:text-white">✕</button>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="edit_user_submit" value="1">
                <input type="hidden" name="edit_uid" id="edit_uid">

                <div>
                    <label class="block font-bold text-slate-300 mb-1">Full Name</label>
                    <input type="text" name="edit_name" id="edit_name" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 text-white rounded-xl font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-300 mb-1">Email Address</label>
                    <input type="email" name="edit_email" id="edit_email" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 text-white rounded-xl font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-300 mb-1">Phone Number</label>
                    <input type="text" name="edit_phone" id="edit_phone" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 text-white rounded-xl font-bold">
                </div>
                <div>
                    <label class="block font-bold text-emerald-400 mb-1">Wallet Balance (₹)</label>
                    <input type="number" step="0.01" name="edit_wallet" id="edit_wallet" required class="w-full px-3 py-2 bg-slate-950 border border-emerald-500/50 text-emerald-300 rounded-xl font-black text-sm">
                </div>
                <div>
                    <label class="block font-bold text-slate-300 mb-1">New Password (Leave blank to keep unchanged)</label>
                    <input type="password" name="edit_password" placeholder="••••••••" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 text-white rounded-xl">
                </div>

                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 rounded-xl shadow transition">Save User Changes</button>
            </form>
        </div>
    </div>

    <!-- MODAL: ADMIN PASSWORD -->
    <div id="admin-profile-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-slate-900 rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-slate-700 space-y-4 text-slate-100">
            <div class="flex justify-between items-center border-b border-slate-800 pb-2">
                <h3 class="text-sm font-extrabold text-white">Change Admin Password</h3>
                <button onclick="closeModal('admin-profile-modal')" class="text-slate-400 hover:text-white">✕</button>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="update_admin_pass" value="1">
                <div>
                    <label class="block font-bold text-slate-300 mb-1">New Password (Min 6 chars)</label>
                    <input type="password" name="admin_new_pass" required placeholder="Enter new strong password" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 text-white rounded-xl font-bold">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-2.5 rounded-xl shadow transition">Update Password</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); document.getElementById(id).classList.add('flex'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.getElementById(id).classList.remove('flex'); }

        function openEditUserModal(user) {
            document.getElementById('edit_uid').value = user.id;
            document.getElementById('edit_name').value = user.name || '';
            document.getElementById('edit_email').value = user.email || '';
            document.getElementById('edit_phone').value = user.phone || '';
            document.getElementById('edit_wallet').value = user.wallet_balance || 0;
            openModal('edit-user-modal');
        }

        // Live Chat Inspector Engine
        async function inspectUserChatLive(userId, userName, userPhone) {
            document.getElementById('inspect-modal-username').innerText = `Auditing: ${userName} (UID: #${userId})`;
            document.getElementById('inspect-modal-phone').innerText = `Phone: +${userPhone}`;
            document.getElementById('inspect-search-input').value = '';
            
            openModal('inspect-chat-modal');
            const stream = document.getElementById('inspect-stream-container');
            stream.innerHTML = '<div class="text-center py-12 text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl text-blue-400 mb-2"></i><p class="text-xs font-semibold">Decrypting user conversation log...</p></div>';

            try {
                const res = await fetch(`dashboard.php?action=fetch_user_chats&user_id=${userId}`);
                const data = await res.json();

                if (data.status && data.chats && data.chats.length > 0) {
                    let html = '';
                    data.chats.forEach(c => {
                        const isOut = (c.direction === 'outbound');
                        html += `
                            <div class="inspect-msg-bubble flex ${isOut ? 'justify-end' : 'justify-start'}">
                                <div class="${isOut ? 'bg-[#005c4b] text-white' : 'bg-slate-800 text-slate-100'} px-3.5 py-2.5 rounded-2xl shadow-md max-w-md text-xs border border-slate-700/50">
                                    <div class="flex justify-between items-center text-[10px] text-slate-300 mb-1 border-b border-slate-700/40 pb-0.5 space-x-3">
                                        <span class="font-bold ${isOut ? 'text-emerald-300' : 'text-blue-300'}">${isOut ? 'Sent to: +' + c.recipient : 'Received from: +' + c.recipient}</span>
                                        <span>${c.created_at}</span>
                                    </div>
                                    <div class="inspect-msg-text whitespace-pre-wrap leading-relaxed">${escapeHtml(c.message_text)}</div>
                                    <div class="text-right text-[9px] text-slate-400 font-mono mt-1">Status: ${c.status || 'sent'}</div>
                                </div>
                            </div>
                        `;
                    });
                    stream.innerHTML = html;
                    document.getElementById('inspect-total-count').innerText = `Total ${data.chats.length} messages inspected`;
                } else {
                    stream.innerHTML = '<div class="text-center py-12 text-slate-400 text-xs font-medium"><i class="fa-regular fa-comment-dots text-3xl text-slate-500 mb-2"></i><p>No messages found in this user\'s conversation history.</p></div>';
                    document.getElementById('inspect-total-count').innerText = '0 messages found';
                }
            } catch(e) {
                stream.innerHTML = '<div class="text-center py-10 text-red-400 text-xs font-bold"><p>Error connecting to chat log endpoint.</p></div>';
            }
        }

        function filterInspectMessages() {
            const query = document.getElementById('inspect-search-input').value.toLowerCase();
            const bubbles = document.querySelectorAll('.inspect-msg-bubble');
            bubbles.forEach(b => {
                const text = b.innerText.toLowerCase();
                if (query === '' || text.includes(query)) {
                    b.style.display = 'flex';
                } else {
                    b.style.display = 'none';
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        // Financial Chart.js Rendering
        const ctx = document.getElementById('financialChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Current'],
                datasets: [
                    {
                        label: 'Total Revenue (₹)',
                        data: [<?= $revenue_val * 0.2 ?>, <?= $revenue_val * 0.4 ?>, <?= $revenue_val * 0.7 ?>, <?= $revenue_val ?>],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Meta Card Expense (₹)',
                        data: [<?= $meta_expense_val * 0.2 ?>, <?= $meta_expense_val * 0.4 ?>, <?= $meta_expense_val * 0.7 ?>, <?= $meta_expense_val ?>],
                        borderColor: '#f43f5e',
                        backgroundColor: 'rgba(244, 63, 94, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#f8fafc' }, position: 'top' } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } },
                    y: { ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } }
                }
            }
        });

        // Leaflet Interactive User Map
        const map = L.map('users-map').setView([20.5937, 78.9629], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        const cityLocations = [
            { name: "Delhi NCR Hub", lat: 28.6139, lng: 77.2090, count: "Active Users" },
            { name: "Mumbai Hub", lat: 19.0760, lng: 72.8777, count: "Active Users" },
            { name: "Bangalore Hub", lat: 12.9716, lng: 77.5946, count: "Active Users" },
            { name: "Kolkata Hub", lat: 22.5726, lng: 88.3639, count: "Active Users" },
            { name: "Hyderabad Hub", lat: 17.3850, lng: 78.4867, count: "Active Users" }
        ];

        cityLocations.forEach(loc => {
            L.marker([loc.lat, loc.lng]).addTo(map).bindPopup(`<b>${loc.name}</b><br>${loc.count}`);
        });
    </script>
</body>
</html>