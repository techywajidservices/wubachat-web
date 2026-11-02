<?php
// admin.php - Complete Enterprise Master Admin & Profit Center (Fully Error-Safe Edition)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$config_path = __DIR__ . '/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die("Error: config.php file is missing in root directory.");
}

$admin_passkey = 'wuba_admin_2026';
if (($_GET['key'] ?? '') !== $admin_passkey && !isset($_SESSION['is_admin'])) {
    if (isset($_POST['admin_login_key']) && $_POST['admin_login_key'] === $admin_passkey) {
        $_SESSION['is_admin'] = true;
    } else {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><title>Admin Access - Wubachat</title><script src="https://cdn.tailwindcss.com"></script></head>
        <body class="bg-gray-100 flex items-center justify-center h-screen p-4">
            <form method="POST" class="bg-white p-8 rounded-3xl shadow-xl max-w-sm w-full space-y-4">
                <div class="text-center">
                    <img src="/assets/logo.png" alt="Wubachat" class="h-10 mx-auto mb-2" onerror="this.src='https://placehold.co/140x45/009b67/ffffff?text=Wubachat'">
                    <h2 class="text-sm font-extrabold text-gray-800">Master Admin Console</h2>
                </div>
                <input type="password" name="admin_login_key" placeholder="Enter Admin Master Key" required class="w-full px-4 py-2.5 bg-gray-50 border rounded-2xl text-xs focus:outline-none focus:ring-1 focus:ring-[#009b67]">
                <button type="submit" class="w-full bg-[#009b67] text-white font-bold py-3 rounded-2xl text-xs uppercase shadow transition">Authorize Entry</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
}

// 1. Date Range Filter Logic
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date'] ?? date('Y-m-d');

// Safe Fetch Helpers with Fallbacks
$pay_settings = ['upi_id' => 'wubachat@okaxis', 'payee_name' => 'Wubachat Payments', 'bank_name' => 'HDFC Bank', 'account_number' => '', 'ifsc_code' => ''];
try {
    if (isset($pdo)) {
        $stmt_pay_set = $pdo->query("SELECT * FROM admin_payment_settings WHERE id = 1");
        if ($row = $stmt_pay_set->fetch()) {
            $pay_settings = $row;
        }
    }
} catch (Exception $e) {}

$total_revenue = 0.00;
try {
    if (isset($pdo)) {
        $stmt_rev = $pdo->prepare("SELECT SUM(amount) as total FROM wallet_transactions WHERE status = 'approved' AND type = 'credit' AND DATE(created_at) BETWEEN ? AND ?");
        $stmt_rev->execute([$from_date, $to_date]);
        $total_revenue = (float)($stmt_rev->fetch()['total'] ?? 0.00);
    }
} catch (Exception $e) {}

$total_paid_msgs = 0;
$total_charged_inr = 0.00;
try {
    if (isset($pdo)) {
        $stmt_msg_paid = $pdo->prepare("SELECT COUNT(*) as count, SUM(cost) as charged FROM messages WHERE direction = 'outbound' AND status = 'delivered' AND cost > 0 AND DATE(created_at) BETWEEN ? AND ?");
        $stmt_msg_paid->execute([$from_date, $to_date]);
        $paid_msg_row = $stmt_msg_paid->fetch();
        $total_paid_msgs  = (int)($paid_msg_row['count'] ?? 0);
        $total_charged_inr = (float)($paid_msg_row['charged'] ?? 0.00);
    }
} catch (Exception $e) {}

$total_free_msgs = 0;
try {
    if (isset($pdo)) {
        $stmt_msg_free = $pdo->prepare("SELECT COUNT(*) as count FROM messages WHERE cost = 0.00 AND DATE(created_at) BETWEEN ? AND ?");
        $stmt_msg_free->execute([$from_date, $to_date]);
        $total_free_msgs = (int)($stmt_msg_free->fetch()['count'] ?? 0);
    }
} catch (Exception $e) {}

$meta_unit_cost = 0.15;
$estimated_meta_expense = $total_paid_msgs * $meta_unit_cost;
$estimated_net_profit = $total_charged_inr - $estimated_meta_expense;

$total_users_count = 0;
try { if (isset($pdo)) $total_users_count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(); } catch (Exception $e) {}

$deleted_users_count = 0;
try { if (isset($pdo)) $deleted_users_count = (int)$pdo->query("SELECT COUNT(*) FROM deleted_users_log")->fetchColumn(); } catch (Exception $e) {}

$transactions = [];
try {
    if (isset($pdo)) {
        $stmt_tx = $pdo->prepare("SELECT t.*, u.name as user_name, u.phone as user_phone FROM wallet_transactions t LEFT JOIN users u ON t.user_id = u.id WHERE DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.id DESC");
        $stmt_tx->execute([$from_date, $to_date]);
        $transactions = $stmt_tx->fetchAll();
    }
} catch (Exception $e) {}

$activities = [];
try {
    if (isset($pdo)) {
        $stmt_act = $pdo->prepare("SELECT * FROM activity_logs WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC LIMIT 100");
        $stmt_act->execute([$from_date, $to_date]);
        $activities = $stmt_act->fetchAll();
    }
} catch (Exception $e) {}

$deleted_logs = [];
try {
    if (isset($pdo)) {
        $stmt_del = $pdo->prepare("SELECT * FROM deleted_users_log WHERE DATE(deleted_at) BETWEEN ? AND ? ORDER BY id DESC");
        $stmt_del->execute([$from_date, $to_date]);
        $deleted_logs = $stmt_del->fetchAll();
    }
} catch (Exception $e) {}

$users_list = [];
try {
    if (isset($pdo)) {
        $users_list = $pdo->query("SELECT id, name, email, phone, wallet_balance, created_at FROM users ORDER BY id DESC LIMIT 100")->fetchAll();
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Admin & Profit Console - Wubachat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body class="bg-[#f4f7fb] text-gray-800 p-4 lg:p-8">
    <div class="max-w-7xl mx-auto space-y-6">

        <!-- Top Header & Actions -->
        <header class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100 flex flex-wrap justify-between items-center gap-4">
            <div class="flex items-center space-x-3">
                <img src="/assets/logo.png" alt="Wubachat" class="h-10 object-contain" onerror="this.src='https://placehold.co/140x45/009b67/ffffff?text=Wubachat'">
                <div>
                    <h1 class="text-lg font-black text-gray-800">Wubachat Master Business Command Center</h1>
                    <p class="text-xs text-gray-400">Auditing, Bank Payout Receiver, Live Analytics & Real Profit</p>
                </div>
            </div>

            <div class="flex items-center space-x-2 no-print">
                <button onclick="openBankSettingsModal()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-4 py-2.5 rounded-2xl text-xs font-bold transition flex items-center space-x-1.5 shadow-sm">
                    <i class="fa-solid fa-building-columns"></i> <span>Payout Bank & UPI Setup</span>
                </button>
                <button onclick="window.print()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-2xl text-xs font-bold transition flex items-center space-x-1.5 shadow-sm">
                    <i class="fa-solid fa-file-pdf text-red-500"></i> <span>Export PDF Report</span>
                </button>
                <a href="dashboard.php" class="bg-[#009b67] hover:bg-[#008357] text-white px-4 py-2.5 rounded-2xl text-xs font-bold transition shadow-sm">
                    Open User App
                </a>
            </div>
        </header>

        <!-- Date Filter Form -->
        <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100 no-print">
            <form method="GET" class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center space-x-3">
                    <span class="text-xs font-bold text-gray-600 flex items-center"><i class="fa-regular fa-calendar-days mr-1.5 text-[#009b67]"></i> Filter Range:</span>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs font-semibold">
                    <span class="text-xs text-gray-400">to</span>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs font-semibold">
                    <button type="submit" class="bg-[#009b67] text-white px-4 py-2 rounded-xl text-xs font-bold shadow hover:bg-[#008357]">Apply Date Filter</button>
                </div>
                <div class="text-xs text-gray-400 font-medium">
                    Showing statistics from <strong class="text-gray-700"><?= htmlspecialchars($from_date) ?></strong> to <strong class="text-gray-700"><?= htmlspecialchars($to_date) ?></strong>
                </div>
            </form>
        </div>

        <!-- 5 Key Metric Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Total UPI Collections</span>
                <h2 class="text-2xl font-black text-gray-800">₹<?= number_format($total_revenue, 2) ?></h2>
                <p class="text-[10px] text-emerald-600 font-bold">Direct to your bank</p>
            </div>

            <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Net Real Profit</span>
                <h2 class="text-2xl font-black text-emerald-600">₹<?= number_format(max(0, $estimated_net_profit), 2) ?></h2>
                <p class="text-[10px] text-gray-400">Margin: ~₹0.35 per msg</p>
            </div>

            <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">WhatsApp Paid Msgs</span>
                <h2 class="text-2xl font-black text-blue-600"><?= number_format($total_paid_msgs) ?></h2>
                <p class="text-[10px] text-gray-400">Charged @ ₹0.50</p>
            </div>

            <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Free Internal Msgs</span>
                <h2 class="text-2xl font-black text-purple-600"><?= number_format($total_free_msgs) ?></h2>
                <p class="text-[10px] text-gray-400">WubaChat-to-WubaChat</p>
            </div>

            <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm space-y-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Users Ratio</span>
                <h2 class="text-2xl font-black text-gray-800"><?= $total_users_count ?> <span class="text-xs text-red-500 font-bold">(<?= $deleted_users_count ?> del)</span></h2>
                <p class="text-[10px] text-gray-400">Active vs Deleted</p>
            </div>
        </div>

        <!-- 2 Tabbed Modules -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- 1. Real-Time Activity Audit Trail -->
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm space-y-4">
                <h3 class="text-sm font-extrabold text-gray-800 flex items-center">
                    <i class="fa-solid fa-list-check text-[#009b67] mr-2"></i> Live Activity Audit Trail (Log)
                </h3>
                <div class="overflow-y-auto max-h-80 divide-y divide-gray-50 text-xs">
                    <?php if (empty($activities)): ?>
                        <p class="text-gray-400 text-center py-6">No audit activities logged in this date range.</p>
                    <?php else: ?>
                        <?php foreach ($activities as $a): ?>
                            <div class="py-2.5 flex justify-between items-start">
                                <div>
                                    <span class="font-bold text-gray-800 uppercase text-[10px] bg-gray-100 px-2 py-0.5 rounded-md mr-1.5"><?= htmlspecialchars($a['activity_type'] ?? '') ?></span>
                                    <span class="text-gray-600 font-medium"><?= htmlspecialchars($a['description'] ?? '') ?></span>
                                    <p class="text-[10px] text-gray-400 mt-0.5">Phone: +<?= htmlspecialchars($a['phone'] ?: 'N/A') ?> | IP: <?= htmlspecialchars($a['ip_address'] ?? '') ?></p>
                                </div>
                                <span class="text-[10px] text-gray-400 shrink-0 font-mono"><?= substr($a['created_at'] ?? '', 11, 5) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Recent Payments & Recharges -->
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm space-y-4">
                <h3 class="text-sm font-extrabold text-gray-800 flex items-center">
                    <i class="fa-solid fa-receipt text-[#009b67] mr-2"></i> User Wallet Recharges
                </h3>
                <div class="overflow-x-auto max-h-80">
                    <table class="w-full text-xs text-left">
                        <thead class="text-gray-400 border-b uppercase text-[10px]">
                            <tr>
                                <th class="pb-2">User / Phone</th>
                                <th class="pb-2">Amount</th>
                                <th class="pb-2">UTR Reference</th>
                                <th class="pb-2">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="4" class="py-4 text-center text-gray-400">No transactions found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): ?>
                                    <tr>
                                        <td class="py-2.5 font-bold text-gray-800"><?= htmlspecialchars($t['user_name'] ?? 'User') ?><br><span class="text-[10px] text-gray-400">+<?= htmlspecialchars($t['user_phone'] ?? '') ?></span></td>
                                        <td class="py-2.5 font-black text-[#009b67]">₹<?= number_format($t['amount'] ?? 0, 2) ?></td>
                                        <td class="py-2.5 font-mono text-gray-600"><?= htmlspecialchars($t['utr_number'] ?? '') ?></td>
                                        <td class="py-2.5 text-gray-400 text-[10px]"><?= substr($t['created_at'] ?? '', 0, 16) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Registered User Balances & Deleted Accounts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Active Users Directory -->
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm space-y-4">
                <h3 class="text-sm font-extrabold text-gray-800 flex items-center">
                    <i class="fa-solid fa-users text-[#009b67] mr-2"></i> Active Users & Current Balances
                </h3>
                <div class="overflow-x-auto max-h-80">
                    <table class="w-full text-xs text-left">
                        <thead class="text-gray-400 border-b uppercase text-[10px]">
                            <tr>
                                <th class="pb-2">Name</th>
                                <th class="pb-2">Mobile</th>
                                <th class="pb-2">Wallet</th>
                                <th class="pb-2">Joined</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (empty($users_list)): ?>
                                <tr><td colspan="4" class="py-4 text-center text-gray-400">No active users found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users_list as $u): ?>
                                    <tr>
                                        <td class="py-2 font-bold text-gray-800"><?= htmlspecialchars($u['name'] ?? 'User') ?></td>
                                        <td class="py-2 text-gray-600 font-semibold">+<?= htmlspecialchars($u['phone'] ?? '') ?></td>
                                        <td class="py-2 font-black text-[#009b67]">₹<?= number_format($u['wallet_balance'] ?? 0, 2) ?></td>
                                        <td class="py-2 text-gray-400 text-[10px]"><?= substr($u['created_at'] ?? '', 0, 10) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Deleted Accounts Archive -->
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm space-y-4">
                <h3 class="text-sm font-extrabold text-gray-800 flex items-center text-red-600">
                    <i class="fa-solid fa-user-xmark mr-2"></i> Deleted Accounts Tracker
                </h3>
                <div class="overflow-x-auto max-h-80">
                    <table class="w-full text-xs text-left">
                        <thead class="text-gray-400 border-b uppercase text-[10px]">
                            <tr>
                                <th class="pb-2">Deleted User</th>
                                <th class="pb-2">Phone</th>
                                <th class="pb-2">Forfeited Wallet</th>
                                <th class="pb-2">Deleted Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (empty($deleted_logs)): ?>
                                <tr><td colspan="4" class="py-4 text-center text-gray-400">No deleted accounts found in this date range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($deleted_logs as $d): ?>
                                    <tr>
                                        <td class="py-2 font-bold text-gray-800"><?= htmlspecialchars($d['name'] ?? '') ?></td>
                                        <td class="py-2 text-gray-600 font-semibold">+<?= htmlspecialchars($d['phone'] ?? '') ?></td>
                                        <td class="py-2 font-bold text-red-500">₹<?= number_format($d['deleted_balance'] ?? 0, 2) ?></td>
                                        <td class="py-2 text-gray-400 text-[10px]"><?= substr($d['deleted_at'] ?? '', 0, 16) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

    <!-- POPUP MODAL: BANK & UPI RECEIVER SETTINGS -->
    <div id="bank-settings-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 no-print">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex justify-between items-center border-b border-gray-100 pb-3">
                <h3 class="text-base font-extrabold text-gray-800"><i class="fa-solid fa-building-columns text-[#009b67] mr-1.5"></i> Receiver Bank & UPI Setup</h3>
                <button onclick="closeBankSettingsModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <p class="text-xs text-gray-500">Whenever you update these details, all user payments on the portal will instantly route to this UPI / Bank Account.</p>

            <form onsubmit="handleSaveBankSettings(event)" class="space-y-3.5 pt-1">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Your Primary UPI ID (GPay / PhonePe / Paytm)</label>
                    <input type="text" id="set-upi-id" value="<?= htmlspecialchars($pay_settings['upi_id'] ?? '') ?>" required class="w-full px-3.5 py-2 bg-gray-50 border rounded-xl text-xs font-bold text-gray-800">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Payee / Business Name</label>
                    <input type="text" id="set-payee-name" value="<?= htmlspecialchars($pay_settings['payee_name'] ?? '') ?>" required class="w-full px-3.5 py-2 bg-gray-50 border rounded-xl text-xs font-semibold">
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Bank Name</label>
                        <input type="text" id="set-bank-name" value="<?= htmlspecialchars($pay_settings['bank_name'] ?? '') ?>" placeholder="e.g. HDFC Bank" class="w-full px-3.5 py-2 bg-gray-50 border rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">IFSC Code</label>
                        <input type="text" id="set-ifsc-code" value="<?= htmlspecialchars($pay_settings['ifsc_code'] ?? '') ?>" placeholder="HDFC000123" class="w-full px-3.5 py-2 bg-gray-50 border rounded-xl text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Bank Account Number</label>
                    <input type="text" id="set-account-no" value="<?= htmlspecialchars($pay_settings['account_number'] ?? '') ?>" placeholder="50100XXXXXXXX" class="w-full px-3.5 py-2 bg-gray-50 border rounded-xl text-xs">
                </div>

                <button type="submit" id="save-bank-btn" class="w-full bg-[#009b67] hover:bg-[#008357] text-white font-bold py-3 rounded-xl text-xs uppercase tracking-wider shadow-lg transition">
                    Save Receiver Details
                </button>
            </form>
        </div>
    </div>

    <script>
        function openBankSettingsModal() { document.getElementById('bank-settings-modal').classList.remove('hidden'); document.getElementById('bank-settings-modal').classList.add('flex'); }
        function closeBankSettingsModal() { document.getElementById('bank-settings-modal').classList.add('hidden'); document.getElementById('bank-settings-modal').classList.remove('flex'); }

        async function handleSaveBankSettings(e) {
            e.preventDefault();
            const btn = document.getElementById('save-bank-btn');
            btn.disabled = true;
            btn.innerText = 'Updating...';

            const fd = new FormData();
            fd.append('upi_id', document.getElementById('set-upi-id').value.trim());
            fd.append('payee_name', document.getElementById('set-payee-name').value.trim());
            fd.append('bank_name', document.getElementById('set-bank-name').value.trim());
            fd.append('account_number', document.getElementById('set-account-no').value.trim());
            fd.append('ifsc_code', document.getElementById('set-ifsc-code').value.trim());

            try {
                const res = await fetch('api/update_payment_settings.php', { method: 'POST', body: fd });
                const data = await res.json();
                alert(data.message);
                closeBankSettingsModal();
                location.reload();
            } catch(e) { alert('Update failed'); }
            finally { btn.disabled = false; btn.innerText = 'Save Receiver Details'; }
        }
    </script>
</body>
</html>