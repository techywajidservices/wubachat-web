<?php
// dashboard.php - Complete Wubachat User Console with Dynamic UPI Amount-Linked QR Code & Strict ₹100 Min Recharge Limit
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];
session_write_close(); // <--- Yeh line browser aur background requests ka deadlock khatam karegi

require_once __DIR__ . '/config.php';

// 1. Fetch user profile & wallet
$display_name   = 'User';
$wallet_balance = 0.00;
$user_email     = '';
$user_phone     = '';
$user_avatar    = '';
$user_bio       = 'Hey there! I am using Wubachat.';

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch();

    if ($current_user) {
        $display_name   = $current_user['name'] ?? $current_user['full_name'] ?? $current_user['business_name'] ?? 'User';
        $wallet_balance = $current_user['wallet_balance'] ?? 0.00;
        $user_email     = $current_user['email'] ?? '';
        $user_phone     = $current_user['phone'] ?? ($current_user['mobile'] ?? '');
        $user_avatar    = $current_user['avatar_url'] ?? $current_user['avatar'] ?? $current_user['profile_pic'] ?? '';
        if (!empty($current_user['bio'])) {
            $user_bio = $current_user['bio'];
        }
    }
} catch (Exception $e) {}

$user_avatar = trim((string)$user_avatar);
$avatar_path = parse_url($user_avatar, PHP_URL_PATH);
$avatar_scheme = parse_url($user_avatar, PHP_URL_SCHEME);
$avatar_extension = strtolower(pathinfo(is_string($avatar_path) ? $avatar_path : '', PATHINFO_EXTENSION));
$valid_avatar_extension = in_array($avatar_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);
$valid_avatar_location = $avatar_scheme === null
    ? substr($user_avatar, 0, 2) !== '//'
    : is_string($avatar_scheme)
        && in_array(strtolower($avatar_scheme), ['http', 'https'], true)
        && filter_var($user_avatar, FILTER_VALIDATE_URL) !== false;

if (
    $user_avatar === ''
    || stripos($user_avatar, 'dashboard.php') !== false
    || !$valid_avatar_extension
    || !$valid_avatar_location
) {
    $user_avatar = '';
}

$avatar_placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%23d1fae5%22/%3E%3Ccircle cx=%2250%22 cy=%2236%22 r=%2218%22 fill=%22%2300a884%22/%3E%3Cpath d=%22M15 95c2-22 15-34 35-34s33 12 35 34%22 fill=%22%2300a884%22/%3E%3C/svg%3E';

// Fetch registered/active WhatsApp number strictly for this account
$connected_whatsapp_phone = $user_phone;
$active_phone_number_id = '';
try {
    $stmt_wp = $pdo->prepare("SELECT phone_number, phone_number_id, access_token, waba_id FROM user_whatsapp_accounts WHERE user_id = ? AND status = 'active' ORDER BY (access_token IS NOT NULL AND access_token != '') DESC, id DESC LIMIT 1");
    $stmt_wp->execute([$user_id]);
    $wp_row = $stmt_wp->fetch();
    if ($wp_row) {
        if (!empty($wp_row['phone_number'])) $connected_whatsapp_phone = $wp_row['phone_number'];
        if (!empty($wp_row['phone_number_id'])) $active_phone_number_id = $wp_row['phone_number_id'];
    }
} catch (Exception $e) {}

// 2. Fetch Dynamic Admin UPI Receiver Settings
$admin_upi   = 'wubachat@okaxis';
$admin_payee = 'Wubachat Payments';
try {
    $stmt_pay = $pdo->query("SELECT upi_id, payee_name FROM admin_payment_settings WHERE id = 1");
    $pay_row = $stmt_pay->fetch();
    if ($pay_row && !empty($pay_row['upi_id'])) {
        $admin_upi   = $pay_row['upi_id'];
        $admin_payee = $pay_row['payee_name'] ?: 'Wubachat Payments';
    }
} catch (Exception $e) {}

// 3. Dynamic Meta Daily Limit Tier & Live Today's Counter Fetch
$is_meta_connected = !empty($active_phone_number_id);
$meta_max_quota = 250;
$meta_tier_label = 'Tier 250/day';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$cached_meta_tier = $_SESSION['cached_meta_tier'] ?? null;
$cached_meta_tier_time = $_SESSION['cached_meta_tier_time'] ?? 0;
session_write_close();

if (isset($cached_meta_tier) && (time() - $cached_meta_tier_time) < 3600) {
    $meta_max_quota = $cached_meta_tier['quota'];
    $meta_tier_label = $cached_meta_tier['label'];
} else {
    try {
        if ($wp_row && !empty($wp_row['access_token']) && !empty($wp_row['phone_number_id'])) {
            $graph_ver = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';
            $limit_url = "https://graph.facebook.com/{$graph_ver}/{$wp_row['phone_number_id']}?fields=messaging_limit_tier";
            $ch = curl_init($limit_url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$wp_row['access_token']}"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $limit_res = curl_exec($ch);
            curl_close($ch);
            $limit_data = json_decode($limit_res, true);
            if (!empty($limit_data['messaging_limit_tier'])) {
                $tier_raw = str_replace('TIER_', '', $limit_data['messaging_limit_tier']);
                if ($tier_raw === '250') { $meta_max_quota = 250; }
                elseif ($tier_raw === '1K') { $meta_max_quota = 1000; }
                elseif ($tier_raw === '2K' || $tier_raw === '2000') { $meta_max_quota = 2000; }
                elseif ($tier_raw === '10K') { $meta_max_quota = 10000; }
                elseif ($tier_raw === '100K') { $meta_max_quota = 100000; }
                $meta_tier_label = 'Tier ' . $tier_raw . '/day';

                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }
                $_SESSION['cached_meta_tier'] = ['quota' => $meta_max_quota, 'label' => $meta_tier_label];
                $_SESSION['cached_meta_tier_time'] = time();
                session_write_close();
            }
        }
    } catch (Exception $e) {}
}

$today_sent_count = 0;
try {
    $stmtToday = $pdo->prepare("
        SELECT COUNT(DISTINCT recipient_phone) as total_today 
        FROM messages 
                WHERE user_id = ? AND direction = 'outbound' AND sent_via = 'whatsapp'
                    AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
    ");
    $stmtToday->execute([$user_id]);
    $todayRow = $stmtToday->fetch();
    $today_sent_count = (int)($todayRow['total_today'] ?? 0);
} catch (Exception $e) {}

$active_team_users = 1;
try {
    $stmtUsersCount = $pdo->query("SELECT COUNT(*) FROM users");
    $fetchedCount = (int)$stmtUsersCount->fetchColumn();
    if ($fetchedCount > 0) {
        $active_team_users = min(20, $fetchedCount);
    }
} catch (Exception $e) {}

$shared_user_quota_share = (int)floor($meta_max_quota / max(1, $active_team_users));
$meta_remaining_quota = max(0, $meta_max_quota - $today_sent_count);

$auto_reply_enabled = 0;
$auto_reply_msg = '';
try {
    $stmt_ar = $pdo->prepare("SELECT is_enabled, reply_message FROM auto_replies WHERE user_id = ?");
    $stmt_ar->execute([$user_id]);
    $ar_row = $stmt_ar->fetch();
    if ($ar_row) {
        $auto_reply_enabled = $ar_row['is_enabled'];
        $auto_reply_msg = $ar_row['reply_message'] ?? '';
    }
} catch (Exception $e) {}

function formatTimeIST($datetimeStr) {
    if (empty($datetimeStr)) return '';
    try {
        $dt = new DateTime($datetimeStr, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('H:i');
    } catch (Exception $e) {
        return substr($datetimeStr, 11, 5);
    }
}

$contacts = [];
$seen_phones = [];
$total_unread_badge = 0;

try {
    // 1. Direct fetch contacts (Zero nested subqueries for instant load)
    $stmt_c = $pdo->prepare("SELECT id, contact_name, phone_number, is_archived, is_important, created_at FROM contacts WHERE user_id = ?");
    $stmt_c->execute([$user_id]);
    $raw_contacts = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch only latest message per recipient in 1 single fast query
    $stmt_m = $pdo->prepare("
        SELECT id, recipient_phone, message_text, created_at, direction, status
        FROM messages 
        WHERE user_id = ? 
        ORDER BY id DESC 
        LIMIT 250
    ");
    $stmt_m->execute([$user_id]);
    $recent_messages = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    $msg_summary = [];
    foreach ($recent_messages as $m) {
        $p = substr(preg_replace('/[^0-9]/', '', $m['recipient_phone']), -10);
        if (!$p) continue;
        if (!isset($msg_summary[$p])) {
            $msg_summary[$p] = [
                'max_id'    => (int)$m['id'],
                'last_msg'  => $m['message_text'],
                'last_time' => $m['created_at'],
                'unread'    => 0,
                'last_inbound_time' => null
            ];
        }
        if ($m['direction'] === 'inbound') {
            if ($m['status'] === 'unread') {
                $msg_summary[$p]['unread']++;
            }
            if (!$msg_summary[$p]['last_inbound_time']) {
                $msg_summary[$p]['last_inbound_time'] = $m['created_at'];
            }
        }
    }

    foreach ($raw_contacts as $rc) {
        $c_phone = preg_replace('/[^0-9]/', '', $rc['phone_number']);
        $k10 = substr($c_phone, -10);
        if (!empty($c_phone) && !isset($seen_phones[$k10])) {
            $c_name = trim($rc['contact_name'] ?? '');
            $is_generic = empty($c_name) || preg_match('/^User\s+\d+$/i', $c_name) || strpos($c_name, '+') === 0;
            $c_name = $is_generic ? '+' . $c_phone : $c_name;
            
            $summary = $msg_summary[$k10] ?? null;
            $unread = $summary['unread'] ?? 0;
            $total_unread_badge += $unread;

            $is_free_reply = false;
            if (!empty($summary['last_inbound_time'])) {
                $inbound_ts = strtotime($summary['last_inbound_time']);
                if ($inbound_ts && (time() - $inbound_ts) <= 86400) {
                    $is_free_reply = true;
                }
            }

            $contacts[] = [
                'id'                   => (int)$rc['id'],
                'contact_name'         => $c_name,
                'phone_number'         => $c_phone,
                'server_max_id'        => (int)($summary['max_id'] ?? 0),
                'is_saved'             => !$is_generic,
                'last_msg'             => $summary['last_msg'] ?? 'No messages yet',
                'last_time'            => !empty($summary['last_time']) ? formatTimeIST($summary['last_time']) : '',
                'unread_count'         => $unread,
                'is_free_reply_active' => $is_free_reply,
                'is_archived'          => (int)($rc['is_archived'] ?? 0) === 1,
                'is_important'         => (int)($rc['is_important'] ?? 0) === 1,
                'raw_last_time'        => $summary['last_time'] ?? $rc['created_at']
            ];
            $seen_phones[$k10] = true;
        }
    }
} catch (Exception $e) {}
    
try {
    $stmt_msg_contacts = $pdo->prepare("
        SELECT latest.id, latest.recipient_phone, latest.message_text AS last_msg,
               latest.created_at AS last_time, latest.direction,
               COALESCE(phone_stats.unread_count, 0) AS unread_count,
               last_inbound.created_at AS last_inbound_time
        FROM messages latest
        INNER JOIN (
            SELECT user_id, recipient_phone, MAX(id) AS latest_id
            FROM messages
            WHERE user_id = ?
              AND recipient_phone IS NOT NULL
              AND recipient_phone != ''
            GROUP BY user_id, recipient_phone
        ) latest_ids ON latest_ids.latest_id = latest.id
        LEFT JOIN (
            SELECT user_id,
                   RIGHT(recipient_phone, 10) AS phone_suffix,
                   SUM(CASE WHEN status = 'unread' AND direction = 'inbound' THEN 1 ELSE 0 END) AS unread_count,
                   MAX(CASE WHEN direction = 'inbound' THEN id END) AS last_inbound_id
            FROM messages
            WHERE user_id = ?
              AND recipient_phone IS NOT NULL
              AND recipient_phone != ''
            GROUP BY user_id, RIGHT(recipient_phone, 10)
        ) phone_stats
            ON phone_stats.user_id = latest.user_id
           AND phone_stats.phone_suffix = RIGHT(latest.recipient_phone, 10)
        LEFT JOIN messages last_inbound
            ON last_inbound.id = phone_stats.last_inbound_id
           AND last_inbound.user_id = latest.user_id
        WHERE latest.user_id = ?
        ORDER BY latest.id DESC
    ");
    $stmt_msg_contacts->execute([$user_id, $user_id, $user_id]);
    $raw_msg_senders = $stmt_msg_contacts->fetchAll();

    foreach ($raw_msg_senders as $rms) {
        $m_phone = preg_replace('/[^0-9]/', '', $rms['recipient_phone'] ?? '');
        $k10 = substr($m_phone, -10);
        if (!empty($m_phone) && !isset($seen_phones[$k10])) {
            $unread = (int)($rms['unread_count'] ?? 0);
            $total_unread_badge += $unread;

            $is_free_reply_active = false;
            if (!empty($rms['last_inbound_time'])) {
                $last_inbound_ts = strtotime($rms['last_inbound_time']);
                if ($last_inbound_ts && (time() - $last_inbound_ts) <= 86400) {
                    $is_free_reply_active = true;
                }
            }

            $contacts[] = [
                'id'                   => count($contacts) + 1,
                'contact_name'         => '+' . $m_phone,
                'phone_number'         => $m_phone,
                'server_max_id'        => (int)($rms['id'] ?? 0),
                'is_saved'             => false,
                'last_msg'             => $rms['last_msg'] ?? 'Tap to open chat',
                'last_time'            => !empty($rms['last_time']) ? formatTimeIST($rms['last_time']) : '',
                'unread_count'         => $unread,
                'is_free_reply_active' => $is_free_reply_active,
                'is_archived'          => false,
                'is_important'         => false,
                'raw_last_time'        => $rms['last_time']
            ];
            $seen_phones[$k10] = true;
        }
    }
} catch (Exception $e) {}

usort($contacts, function($a, $b) {
    $tA = !empty($a['raw_last_time']) ? strtotime($a['raw_last_time']) : 0;
    $tB = !empty($b['raw_last_time']) ? strtotime($b['raw_last_time']) : 0;
    if ($tA === $tB) return 0;
    return ($tA > $tB) ? -1 : 1;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wubachat Console - WhatsApp Web Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- html2pdf Library for direct clean PDF downloads -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        
        .whatsapp-bg {
            background-color: #efeae2;
            background-image: radial-gradient(#d1d7db 0.75px, transparent 0.75px), radial-gradient(#d1d7db 0.75px, #efeae2 0.75px);
            background-size: 30px 30px;
            background-position: 0 0, 15px 15px;
        }
        .highlight-match {
            background-color: #fef08a !important;
            color: #854d0e !important;
            padding: 1px 3px;
            border-radius: 4px;
            font-weight: 700;
        }
        .message-jump-focus {
            animation: jumpHighlight 2s ease-in-out forwards;
        }
        @keyframes jumpHighlight {
            0% { transform: scale(1.03); box-shadow: 0 0 0 4px #22c55e; }
            50% { transform: scale(1.03); box-shadow: 0 0 0 4px #22c55e; }
            100% { transform: scale(1); box-shadow: none; }
        }
        @keyframes marquee {
            0% { transform: translateX(100%); }
            100% { transform: translateX(-100%); }
        }
        .animate-marquee {
            display: inline-block;
            white-space: nowrap;
            animation: marquee 25s linear infinite;
        }
        .animate-marquee:hover { animation-play-state: paused; }
    </style>
</head>
<body class="bg-[#f0f2f5] h-screen flex flex-col overflow-hidden text-gray-800 select-none">
    
    <!-- Instant Floating Toast -->
    <div id="app-toast" class="fixed top-5 right-5 z-[999999] bg-emerald-600 text-white text-xs font-bold px-4 py-2.5 rounded-2xl shadow-2xl flex items-center space-x-2 transition-all duration-300 opacity-0 pointer-events-none transform translate-y-[-10px]">
        <i id="app-toast-icon" class="fa-solid fa-circle-check text-sm"></i>
        <span id="app-toast-msg">Success</span>
    </div>

    <!-- Top Universal Controls Header -->
    <header class="bg-white border-b border-gray-200 px-5 py-2.5 flex justify-between items-center z-20 shadow-sm select-none">
        <div class="flex items-center space-x-3">
            <img src="/assets/logo.png" alt="Wubachat" class="h-8 object-contain" onerror="this.src='https://placehold.co/120x40/009b67/ffffff?text=Wubachat'">
            <div class="flex items-center space-x-2">
                <span class="bg-emerald-50 text-[#009b67] border border-emerald-200 text-xs px-2.5 py-1 rounded-full font-bold flex items-center">
                    <i class="fa-solid fa-user-check mr-1.5 text-[10px]"></i> <?= htmlspecialchars($display_name) ?>
                </span>
                
                <span class="bg-blue-50 text-blue-700 border border-blue-200 text-[11px] px-3 py-1 rounded-xl font-bold flex items-center shadow-xs" title="Official Meta Cloud API Limit (Shared Pool / Active Team Split)">
                    <i class="fa-solid fa-gauge-high mr-1.5 text-blue-500"></i>
                    <span>Meta: <strong><?= $today_sent_count ?></strong> / <?= $meta_max_quota ?> (<?= $meta_remaining_quota ?> Left | Split: ~<?= $shared_user_quota_share ?>/user)</span>
                </span>
            </div>
        </div>

        <div class="flex items-center space-x-2.5">
            <?php if ($is_meta_connected): ?>
                <div class="flex items-center space-x-1.5">
                    <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs px-3 py-1 rounded-xl font-bold flex items-center shadow-sm">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span> Meta Connected
                    </span>
                    <button onclick="handleMetaDisconnect()" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 text-xs px-2.5 py-1 rounded-xl font-bold transition shadow-sm" title="Disconnect Meta API">
                        <i class="fa-solid fa-link-slash mr-1"></i> Disconnect
                    </button>
                </div>
            <?php else: ?>
                <button onclick="openMetaConnectModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-1.5 rounded-xl font-bold flex items-center space-x-1.5 shadow-sm transition">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>Connect WhatsApp (Embedded Signup)</span>
                </button>
            <?php endif; ?>

            <div class="bg-emerald-50 border border-emerald-200 px-3 py-1 rounded-xl flex items-center space-x-1.5 text-xs">
                <i class="fa-solid fa-wallet text-[#009b67]"></i>
                <span class="text-gray-500 font-semibold">Balance:</span>
                <span class="font-extrabold text-[#009b67]" id="top-wallet-display">₹<?= number_format($wallet_balance, 2) ?></span>
                <button onclick="openRechargeModal()" class="bg-[#009b67] hover:bg-[#008357] text-white px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase transition shadow-sm ml-1" title="Add Balance">
                    + Add
                </button>
            </div>

            <button onclick="openBillingAuditModal()" class="px-2.5 py-1 rounded-xl border border-gray-200 text-gray-600 hover:text-[#009b67] hover:bg-emerald-50 transition flex items-center space-x-1 shadow-sm text-xs" title="Message & Wallet Transaction History">
                <i class="fa-solid fa-receipt text-[#009b67]"></i>
                <span class="font-bold">Billing Logs</span>
            </button>

            <button onclick="openBlockedUsersModal()" class="px-2.5 py-1 rounded-xl border border-gray-200 text-gray-600 hover:text-red-600 hover:bg-red-50 transition flex items-center space-x-1 shadow-sm text-xs" title="Blocked Contacts Directory">
                <i class="fa-solid fa-user-slash text-red-500"></i>
                <span class="font-bold">Blocked List</span>
            </button>

            <button onclick="openAutoReplyModal()" class="px-2.5 py-1 rounded-xl border border-gray-200 text-gray-600 hover:text-[#009b67] hover:bg-emerald-50 transition flex items-center space-x-1 shadow-sm text-xs" title="Auto-Reply Settings">
                <i class="fa-solid fa-robot text-[#009b67]"></i>
                <span class="font-bold"><?= $auto_reply_enabled ? 'Bot ON' : 'Bot OFF' ?></span>
            </button>

            <nav class="flex bg-gray-100 p-0.5 rounded-xl space-x-1 text-xs">
                <button onclick="switchTab('chat')" id="tab-chat-btn" class="px-3 py-1 bg-white text-[#009b67] shadow-sm rounded-lg font-bold transition flex items-center space-x-1">
                    <i class="fa-solid fa-comments"></i> <span>Live Chats</span>
                </button>
                <button onclick="switchTab('bulk')" id="tab-bulk-btn" class="px-3 py-1 text-gray-600 hover:text-gray-900 rounded-lg font-bold transition flex items-center space-x-1">
                    <i class="fa-solid fa-paper-plane"></i> <span>Bulk Blast</span>
                </button>
            </nav>
            
            <button onclick="openLogoutModal()" class="flex items-center space-x-1 px-2.5 py-1 rounded-xl text-gray-500 hover:text-red-600 hover:bg-red-50 border border-transparent hover:border-red-200 transition text-xs font-bold" title="Logout Account">
                <i class="fa-solid fa-power-off text-xs"></i>
                <span>Logout</span>
            </button>
        </div>
    </header>

    <!-- Top Alert Banner -->
    <div class="bg-red-600 text-white text-[11px] font-bold py-1 px-4 overflow-hidden shadow-inner flex items-center z-10">
        <span class="bg-red-800 text-white px-2 py-0.5 rounded-md text-[10px] uppercase tracking-wider mr-2 shrink-0 flex items-center shadow-sm">
            <i class="fa-solid fa-triangle-exclamation mr-1 text-amber-300"></i> ALERT
        </span>
        <div class="overflow-hidden w-full relative whitespace-nowrap">
            <div class="animate-marquee">
                ⚠️ Important Notice: If you have not Removed/Deleted your WhatsApp account from your mobile phone, your messages will not be delivered to regular WhatsApp numbers. You can only have free conversations between WubaChat to WubaChat users.
            </div>
        </div>
    </div>

    <!-- Main Workspace Container -->
    <main class="flex-1 flex overflow-hidden">
        <section id="chat-section" class="flex-1 flex w-full">
            
            <!-- 1. Left App Bar -->
            <div class="w-14 bg-[#f0f2f5] border-r border-gray-200 flex flex-col justify-between items-center py-3 select-none">
                <div class="space-y-4 text-center">
                    <button onclick="switchTab('chat'); renderDefaultContactsList();" class="relative w-10 h-10 rounded-xl bg-gray-200/80 text-gray-700 flex items-center justify-center text-base hover:bg-gray-300 transition" title="Chats">
                        <i class="fa-solid fa-message"></i>
                        <span id="sidebar-unread-badge" class="<?= ($total_unread_badge > 0) ? '' : 'hidden' ?> absolute -top-1 -right-1 bg-[#00a884] text-white text-[9px] font-black rounded-full px-1.5 py-0.2"><?= $total_unread_badge ?></span>
                    </button>
                    <button class="w-10 h-10 rounded-xl text-gray-500 hover:text-gray-800 hover:bg-gray-200 flex items-center justify-center text-base transition" title="Status">
                        <i class="fa-solid fa-circle-notch"></i>
                    </button>
                    <button class="w-10 h-10 rounded-xl text-gray-500 hover:text-gray-800 hover:bg-gray-200 flex items-center justify-center text-base transition" title="Channels">
                        <i class="fa-solid fa-newspaper"></i>
                    </button>
                    <button class="w-10 h-10 rounded-xl text-gray-500 hover:text-gray-800 hover:bg-gray-200 flex items-center justify-center text-base transition" title="Communities">
                        <i class="fa-solid fa-users"></i>
                    </button>
                </div>
                <div class="space-y-3 text-center">
                    <button onclick="openSettingsModal()" class="w-10 h-10 rounded-xl text-gray-500 hover:text-gray-800 hover:bg-gray-200 flex items-center justify-center text-base transition" title="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </button>
                    <div onclick="openSettingsModal()" class="w-8 h-8 rounded-full bg-[#00a884] text-white font-bold flex items-center justify-center text-xs shadow-sm cursor-pointer hover:ring-2 hover:ring-[#00a884]/50 transition overflow-hidden">
                        <?php if ($user_avatar !== ''): ?>
                            <img src="<?= htmlspecialchars($user_avatar, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= strtoupper(substr($display_name, 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 2. Contacts & Chats Drawer -->
            <div class="w-80 lg:w-[26rem] bg-white border-r border-gray-200 flex flex-col">
                <div class="p-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-black text-gray-800">Chats</h2>
                    <div class="flex items-center space-x-2 text-gray-500 text-sm">
                        <button onclick="triggerSoftRefresh()" id="manual-refresh-btn" class="bg-emerald-50 hover:bg-emerald-100 text-[#008069] border border-emerald-200 px-2 py-1 rounded-xl text-[11px] font-bold flex items-center space-x-1.5 shadow-xs transition cursor-pointer" title="Sync & Refresh Chats">
                            <i class="fa-solid fa-arrows-rotate text-[10px]" id="manual-refresh-icon"></i>
                            <span>Refresh</span>
                        </button>
                        <button onclick="openModal()" class="p-1.5 hover:bg-gray-100 rounded-lg transition" title="New Chat"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button onclick="openContactBookModal()" class="p-1.5 hover:bg-gray-100 rounded-lg transition" title="Contact Directory"><i class="fa-solid fa-address-book"></i></button>
                        <button onclick="openImportContactsModal()" class="p-1.5 hover:bg-gray-100 rounded-lg transition" title="Import Contacts"><i class="fa-solid fa-file-import"></i></button>
                    </div>
                </div>

                <!-- Deep Global Search Bar -->
                <div class="px-3 pt-2 pb-1.5">
                    <div class="relative flex items-center">
                        <i class="fa-solid fa-search absolute left-3 text-gray-400 text-xs"></i>
                        <input type="text" id="contact-search" oninput="handleUniversalSearch()" placeholder="Search contacts, numbers or words..." class="w-full pl-8 pr-7 py-1.5 bg-[#f0f2f5] border-0 rounded-xl text-xs focus:bg-white focus:ring-1 focus:ring-[#00a884] focus:outline-none transition">
                        <button type="button" id="clear-search-btn" onclick="clearUniversalSearch()" class="hidden absolute right-2.5 text-gray-400 hover:text-gray-600 text-xs">✕</button>
                    </div>
                </div>

                <!-- Quick Filter Tabs -->
                <div class="flex items-center px-3 py-2 space-x-1.5 overflow-x-auto text-[11px] font-semibold border-b border-gray-100 custom-scrollbar">
                    <button type="button" onclick="setContactFilter('all')" id="filter-btn-all" class="px-3 py-1 rounded-full bg-[#e7fce3] text-[#008069] font-bold shrink-0">All</button>
                    <button type="button" onclick="setContactFilter('unread')" id="filter-btn-unread" class="px-3 py-1 rounded-full bg-[#f0f2f5] text-gray-600 hover:bg-gray-200 shrink-0">Unread</button>
                    <button type="button" onclick="setContactFilter('favourites')" id="filter-btn-favourites" class="px-3 py-1 rounded-full bg-[#f0f2f5] text-gray-600 hover:bg-gray-200 shrink-0">Favourites</button>
                    <button type="button" onclick="setContactFilter('important')" id="filter-btn-important" class="px-3 py-1 rounded-full bg-[#f0f2f5] text-gray-600 hover:bg-gray-200 shrink-0">Important</button>
                    <button type="button" onclick="setContactFilter('archive')" id="filter-btn-archive" class="px-3 py-1 rounded-full bg-[#f0f2f5] text-gray-600 hover:bg-gray-200 shrink-0">Archive</button>
                </div>

                <!-- Contacts & Conversations List -->
                <div class="flex-1 overflow-y-auto divide-y divide-gray-100 custom-scrollbar" id="contacts-list-container">
                    <?php if (empty($contacts)): ?>
                        <div id="no-contacts-msg" class="p-8 text-center text-gray-400">
                            <i class="fa-regular fa-address-book text-3xl mb-2 text-gray-300"></i>
                            <p class="text-xs font-medium">No saved contacts yet.</p>
                            <p class="text-[11px] text-gray-400 mt-1">Click the top icons to add contacts.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($contacts as $c): ?>
                            <div onclick="selectChat('<?= htmlspecialchars($c['phone_number']) ?>', '<?= htmlspecialchars(addslashes($c['contact_name'])) ?>', <?= $c['is_saved'] ? 'true' : 'false' ?>)" class="contact-item p-3 hover:bg-[#f5f6f6] cursor-pointer flex items-center justify-between transition border-b border-gray-50/80 group">
                                <div class="flex items-center space-x-3 min-w-0 flex-1">
                                    <div class="relative w-11 h-11 bg-teal-100 text-teal-800 font-black rounded-full flex items-center justify-center text-sm uppercase shrink-0">
                                        <?= strtoupper(substr($c['contact_name'], 0, 2)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between items-baseline mb-0.5">
                                            <h4 class="text-xs font-bold text-gray-900 truncate contact-title"><?= htmlspecialchars($c['contact_name']) ?></h4>
                                            <span class="text-[10px] text-gray-400 shrink-0"><?= htmlspecialchars($c['last_time']) ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <p class="text-[11px] text-gray-500 truncate max-w-[170px] contact-last-msg"><?= htmlspecialchars($c['last_msg']) ?></p>
                                            <?php if ($c['unread_count'] > 0): ?>
                                                <span class="bg-[#25d366] text-white text-[10px] font-extrabold rounded-full w-5 h-5 flex items-center justify-center shadow-sm shrink-0"><?= $c['unread_count'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="relative dropdown-container ml-2">
                                    <button type="button" onclick="toggleContactRowMenu(event, '<?= $c['phone_number'] ?>')" class="opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-gray-700 transition">
                                        <i class="fa-solid fa-chevron-down text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. WhatsApp Main Conversation Area -->
            <div class="flex-1 whatsapp-bg flex flex-col relative" id="chat-window" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDropMedia(event)">
                
                <div id="drag-drop-overlay" class="hidden absolute inset-0 bg-[#00a884]/20 backdrop-blur-xs border-4 border-dashed border-[#00a884] z-40 flex flex-col items-center justify-center text-[#008069] pointer-events-none select-none">
                    <i class="fa-solid fa-cloud-arrow-up text-5xl mb-2 animate-bounce"></i>
                    <h3 class="text-base font-extrabold">Drop photos, videos or documents here to send instantly</h3>
                </div>

                <!-- Chat Header -->
                <div class="bg-[#f0f2f5] px-5 py-2.5 border-b border-gray-200 flex items-center justify-between shadow-sm z-20">
                    <div class="flex items-center space-x-3 cursor-pointer" onclick="openContactDrawer(currentSelectedPhone)">
                        <div class="w-10 h-10 bg-teal-200 text-teal-900 font-extrabold rounded-full flex items-center justify-center text-sm shadow-sm overflow-hidden" id="active-chat-avatar">
                            <i class="fa-brands fa-whatsapp text-xl text-[#00a884]"></i>
                        </div>
                        <div>
                            <div class="flex items-center space-x-2">
                                <h3 class="text-xs font-extrabold text-gray-900 hover:underline" id="active-chat-name">Direct WhatsApp Messenger</h3>
                                <span id="wubachat-presence-badge" class="hidden px-2 py-0.5 rounded-md text-[10px] font-extrabold"></span>
                                <button type="button" id="header-invite-btn" onclick="inviteActiveContact()" class="hidden bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-bold px-2 py-0.5 rounded-full transition shadow-sm">
                                    <i class="fa-solid fa-paper-plane mr-1"></i> Invite to Wubachat
                                </button>
                                <button type="button" id="header-add-contact-btn" onclick="openSaveHeaderContactModal()" class="hidden bg-emerald-100 hover:bg-emerald-200 text-[#008069] text-[10px] font-bold px-2 py-0.5 rounded-full transition">
                                    <i class="fa-solid fa-user-plus mr-1"></i> Save
                                </button>
                            </div>
                            <span class="text-[10px] text-gray-500 font-medium" id="active-chat-sub">online • last seen today at <?= date('H:i') ?></span>
                        </div>
                    </div>

                    <!-- Calling & Header Controls -->
                    <div class="flex items-center space-x-3 text-gray-600 text-sm">
                        <button onclick="toggleSelectMode()" class="p-2 rounded-xl hover:bg-gray-200 hover:text-[#00a884] transition" title="Select Multiple Messages"><i class="fa-regular fa-square-check"></i></button>
                        <button onclick="startScreenShare()" class="p-2 rounded-xl hover:bg-gray-200 hover:text-[#00a884] transition" title="Share Screen"><i class="fa-solid fa-desktop"></i></button>
                        <button onclick="triggerCall('video')" class="p-2 rounded-xl hover:bg-gray-200 hover:text-[#00a884] transition" title="Video Call"><i class="fa-solid fa-video"></i></button>
                        <button onclick="triggerCall('voice')" class="p-2 rounded-xl hover:bg-gray-200 hover:text-[#00a884] transition" title="Voice Call"><i class="fa-solid fa-phone"></i></button>
                        <button onclick="toggleInChatSearch()" class="p-2 rounded-xl hover:bg-gray-200 hover:text-[#00a884] transition" title="Search in Chat"><i class="fa-solid fa-magnifying-glass"></i></button>
                        
                        <div class="relative dropdown-container">
                            <button onclick="toggleOptionsMenu(event)" class="p-2 rounded-xl hover:bg-gray-200 hover:text-gray-800 transition" title="More Options"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                            <div id="options-dropdown" class="hidden absolute right-0 top-10 w-56 bg-white rounded-2xl shadow-xl border border-gray-100 py-2 z-50 text-xs">
                                <button onclick="toggleSelectMode(); toggleOptionsMenu();" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center space-x-2.5">
                                    <i class="fa-regular fa-square-check text-gray-400"></i> <span>Select messages</span>
                                </button>
                                <button onclick="exportCurrentChatHistory(); toggleOptionsMenu();" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center space-x-2.5">
                                    <i class="fa-solid fa-file-arrow-down text-gray-400"></i> <span>Export Chat (.txt)</span>
                                </button>
                                <button onclick="openContactDrawer(currentSelectedPhone); toggleOptionsMenu();" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center space-x-2.5">
                                    <i class="fa-solid fa-circle-info text-gray-400"></i> <span>Contact info</span>
                                </button>
                                <button onclick="openImportBackupModal()" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center space-x-2.5">
                                    <i class="fa-solid fa-file-import text-gray-400"></i> <span>Import Chat (.txt)</span>
                                </button>
                                <hr class="my-1 border-gray-100">
                                <button onclick="clearCurrentChat()" class="w-full text-left px-4 py-2 hover:bg-amber-50 text-amber-600 flex items-center space-x-2.5">
                                    <i class="fa-solid fa-broom"></i> <span>Clear chat history</span>
                                </button>
                                <button onclick="deleteCurrentChat()" class="w-full text-left px-4 py-2 hover:bg-red-50 text-red-600 flex items-center space-x-2.5">
                                    <i class="fa-solid fa-trash-can"></i> <span>Delete conversation</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- In-Chat Search Bar -->
                <div id="in-chat-search-bar" class="hidden bg-gray-50 border-b border-gray-200 px-6 py-2 flex items-center justify-between z-10">
                    <div class="flex items-center space-x-2 flex-1 max-w-md">
                        <i class="fa-solid fa-search text-gray-400 text-xs"></i>
                        <input type="text" id="chat-search-input" oninput="searchInChatMessages()" placeholder="Find word or sentence in this conversation..." class="w-full bg-white border border-gray-200 px-3 py-1 rounded-xl text-xs focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                    </div>
                    <button onclick="toggleInChatSearch()" class="text-gray-400 hover:text-gray-600 text-xs ml-3"><i class="fa-solid fa-xmark"></i> Close</button>
                </div>

                <!-- Live Message Stream -->
                <div class="flex-1 p-6 overflow-y-auto space-y-4 custom-scrollbar select-text" id="messages-stream">
                    <div class="flex justify-center my-4">
                        <span class="bg-white/80 backdrop-blur-sm text-gray-500 text-[11px] font-semibold px-3 py-1 rounded-full shadow-sm">
                            Select a contact to start messaging
                        </span>
                    </div>
                </div>

                <!-- Blocked Contact Notice Strip -->
                <div id="chat-blocked-banner" class="hidden bg-red-100 border-t border-red-200 px-4 py-2.5 text-center text-xs font-bold text-red-700 items-center justify-center space-x-2">
                    <i class="fa-solid fa-ban"></i>
                    <span>You have blocked this contact. Unblock to send messages.</span>
                    <button type="button" onclick="toggleDrawerBlockContact()" class="underline text-red-900 ml-2">Unblock</button>
                </div>

                <!-- Attachment Tray -->
                <div id="attachment-menu" class="hidden absolute bottom-20 left-12 bg-white rounded-3xl shadow-2xl border border-gray-100 p-3 z-30 w-52 space-y-1">
                    <label class="flex items-center space-x-3 p-2 rounded-2xl hover:bg-gray-50 cursor-pointer transition">
                        <span class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-xs"><i class="fa-solid fa-file-lines"></i></span>
                        <span class="text-xs font-semibold text-gray-700">Document</span>
                        <input type="file" multiple onchange="uploadDirectMedia(this, 'document')" class="hidden">
                    </label>
                    <label class="flex items-center space-x-3 p-2 rounded-2xl hover:bg-gray-50 cursor-pointer transition">
                        <span class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xs"><i class="fa-solid fa-images"></i></span>
                        <span class="text-xs font-semibold text-gray-700">Photos & Videos</span>
                        <input type="file" multiple accept="image/*,video/*" onchange="uploadDirectMedia(this, 'image')" class="hidden">
                    </label>
                    <button type="button" onclick="openCameraCapture()" class="w-full flex items-center space-x-3 p-2 rounded-2xl hover:bg-gray-50 cursor-pointer transition">
                        <span class="w-8 h-8 rounded-full bg-pink-100 text-pink-600 flex items-center justify-center text-xs"><i class="fa-solid fa-camera"></i></span>
                        <span class="text-xs font-semibold text-gray-700">Camera</span>
                    </button>
                    <label class="flex items-center space-x-3 p-2 rounded-2xl hover:bg-gray-50 cursor-pointer transition">
                        <span class="w-8 h-8 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs"><i class="fa-solid fa-headphones"></i></span>
                        <span class="text-xs font-semibold text-gray-700">Audio file</span>
                        <input type="file" accept="audio/*" onchange="uploadDirectMedia(this, 'audio')" class="hidden">
                    </label>
                    <button type="button" onclick="shareContactCard()" class="w-full flex items-center space-x-3 p-2 rounded-2xl hover:bg-gray-50 cursor-pointer transition">
                        <span class="w-8 h-8 rounded-full bg-cyan-100 text-cyan-600 flex items-center justify-center text-xs"><i class="fa-solid fa-user"></i></span>
                        <span class="text-xs font-semibold text-gray-700">Contact</span>
                    </button>
                    <button type="button" onclick="createPoll()" class="w-full flex items-center space-x-3 p-2 rounded-2xl hover:bg-gray-50 cursor-pointer transition">
                        <span class="w-8 h-8 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center text-xs"><i class="fa-solid fa-square-poll-vertical"></i></span>
                        <span class="text-xs font-semibold text-gray-700">Poll</span>
                    </button>
                </div>

                <!-- Voice Recording Dynamic Banner -->
                <div id="voice-recording-bar" class="hidden bg-[#f0f2f5] px-5 py-3 border-t border-gray-200 flex items-center justify-between z-20">
                    <div class="flex items-center space-x-3">
                        <span class="w-3 h-3 rounded-full bg-red-500 animate-ping"></span>
                        <span class="text-xs font-bold text-red-600">Recording Voice Note...</span>
                        <span id="recording-timer" class="text-xs font-mono font-bold text-gray-700">00:00</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="cancelVoiceRecording()" class="p-2 rounded-xl text-gray-500 hover:text-red-600 transition" title="Cancel"><i class="fa-solid fa-trash-can text-sm"></i></button>
                        <button type="button" onclick="stopAndSendVoiceRecording()" class="bg-[#00a884] text-white px-4 py-1.5 rounded-xl text-xs font-bold shadow flex items-center space-x-1">
                            <span>Send</span> <i class="fa-solid fa-paper-plane text-[10px]"></i>
                        </button>
                    </div>
                </div>

                <!-- Pasted Screenshot Preview Banner -->
                <div id="pasted-image-preview-bar" class="hidden bg-gray-50 px-6 py-2.5 border-t border-gray-200 flex items-center justify-between z-20">
                    <div class="flex items-center space-x-3">
                        <div class="relative">
                            <img id="pasted-img-thumb" src="" alt="Screenshot" class="h-12 w-12 object-cover rounded-xl border border-gray-300 shadow-sm">
                            <button type="button" onclick="removePastedImage()" class="absolute -top-1.5 -right-1.5 bg-gray-700 text-white rounded-full w-4 h-4 text-[10px] flex items-center justify-center hover:bg-black">✕</button>
                        </div>
                        <span class="text-xs font-bold text-gray-700">Screenshot Attached (Ready to Send)</span>
                    </div>
                    <span class="text-[11px] text-gray-400">Press Enter or click Send</span>
                </div>

                <!-- Bottom Floating Multi-Select Action Bar -->
                <div id="multi-select-bar" class="hidden bg-white border-t border-gray-200 px-6 py-3 flex items-center justify-between z-30 shadow-lg">
                    <div class="flex items-center space-x-3">
                        <button onclick="toggleSelectMode()" class="text-gray-500 hover:text-gray-800 text-sm font-bold"><i class="fa-solid fa-xmark mr-1"></i> Cancel</button>
                        <span class="text-xs font-bold text-[#00a884]" id="selected-messages-count">0 selected</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button onclick="downloadSelectedMedia()" class="px-3 py-1.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold transition flex items-center space-x-1.5"><i class="fa-solid fa-download"></i> <span>Download</span></button>
                        <button onclick="openForwardModal()" class="px-3.5 py-1.5 rounded-xl bg-[#00a884] hover:bg-[#008f6f] text-white text-xs font-bold shadow transition flex items-center space-x-1.5"><i class="fa-solid fa-share"></i> <span>Forward</span></button>
                        <button onclick="deleteSelectedMessages()" class="px-3 py-1.5 rounded-xl bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold transition flex items-center space-x-1.5"><i class="fa-solid fa-trash-can"></i> <span>Delete</span></button>
                    </div>
                </div>

                <!-- Active Quote / Reply Preview Banner -->
                <div id="reply-preview-banner" class="hidden bg-white border-t border-gray-200 px-5 py-2.5 flex items-center justify-between z-15 shadow-sm mx-3 mb-1.5 rounded-xl border-l-4 border-l-[#00a884]">
                    <div class="flex items-center space-x-3 min-w-0">
                        <div class="truncate">
                            <span class="text-[11px] font-extrabold text-[#00a884] block" id="reply-preview-sender">Replying to message</span>
                            <p class="text-xs text-gray-600 truncate max-w-md font-medium" id="reply-preview-text"></p>
                        </div>
                    </div>
                    <button type="button" onclick="cancelReplyMode()" class="text-gray-400 hover:text-gray-600 text-xs p-1.5"><i class="fa-solid fa-xmark text-sm"></i></button>
                </div>
                <!-- Pending Media File Preview Banner -->
                <div id="media-preview-bar" class="hidden bg-white px-5 py-3 border-t border-gray-200 flex items-center justify-between z-20 shadow-sm mx-3 mb-1.5 rounded-2xl border-l-4 border-l-[#00a884]">
                <div class="flex items-center space-x-3 truncate">
                        <div class="w-10 h-10 rounded-xl bg-emerald-100 text-[#00a884] flex items-center justify-center text-lg font-bold shrink-0">
                            <i id="media-preview-icon" class="fa-solid fa-file-lines"></i>
                        </div>
                        <div class="truncate">
                            <span class="text-[10px] font-extrabold text-[#00a884] uppercase tracking-wider block">Attached File Ready</span>
                            <p class="text-xs text-gray-800 font-bold truncate max-w-xs" id="media-preview-filename">filename.ext</p>
                        </div>
                    </div>
                    <button type="button" onclick="cancelPendingMedia()" class="text-gray-400 hover:text-red-500 text-sm p-1.5 transition"><i class="fa-solid fa-xmark"></i> Cancel</button>
                </div>
                <!-- Input & Send Control Bar -->
                <form id="single-chat-form" onsubmit="event.preventDefault()" class="bg-[#f0f2f5] px-4 py-2.5 border-t border-gray-200 flex items-center space-x-2 relative z-10">
                    <input type="hidden" id="target-phone-input" value="">
                    <input type="hidden" id="reply-wamid-input" value="">

                    <button type="button" onclick="toggleEmojiPanel('single', event)" class="text-gray-500 hover:text-amber-500 p-1 text-lg transition" title="Insert Emoji">
                        <i class="fa-regular fa-face-smile"></i>
                    </button>

                    <button type="button" onclick="toggleAttachmentMenu(event)" class="text-gray-500 hover:text-[#00a884] p-1 text-lg transition" title="Attach Media">
                        <i class="fa-solid fa-paperclip"></i>
                    </button>
                    
                    <div class="flex items-center bg-white border border-gray-200 rounded-xl px-2 py-1 space-x-1">
                        <select id="chat-target-lang" class="bg-transparent text-[11px] font-semibold text-gray-700 focus:outline-none cursor-pointer max-w-[105px]">
                            <optgroup label="Popular Languages">
                                <option value="hi-Latn" selected>Hinglish</option>
                                <option value="hi">Hindi (हिंदी)</option>
                                <option value="en">English</option>
                                <option value="ur">Urdu (اردو)</option>
                                <option value="ar">Arabic (العربية)</option>
                                <option value="bn">Bengali (বাংলা)</option>
                                <option value="mr">Marathi (मराठी)</option>
                                <option value="gu">Gujarati (ગુજરાતી)</option>
                                <option value="pa">Punjabi (ਪੰਜਾਬੀ)</option>
                                <option value="ta">Tamil (தமிழ்)</option>
                                <option value="te">Telugu (తెలుగు)</option>
                                <option value="kn">Kannada (ಕನ್ನಡ)</option>
                                <option value="ml">Malayalam (മലയാളം)</option>
                                <option value="es">Spanish (Español)</option>
                                <option value="fr">French (Français)</option>
                                <option value="de">German (Deutsch)</option>
                                <option value="ru">Russian (Русский)</option>
                                <option value="zh-CN">Chinese (Simplified)</option>
                                <option value="ja">Japanese (日本語)</option>
                                <option value="ko">Korean (한국어)</option>
                                <option value="tr">Turkish (Türkçe)</option>
                                <option value="it">Italian (Italiano)</option>
                                <option value="pt">Portuguese (Português)</option>
                                <option value="id">Indonesian (Bahasa)</option>
                                <option value="fa">Persian (فارسی)</option>
                            </optgroup>
                        </select>
                        <button type="button" onclick="translateChatMessage()" id="chat-translate-btn" class="text-[#00a884] hover:text-[#008f6f] text-xs px-1 font-bold" title="Translate Input Message">
                            <i class="fa-solid fa-language text-sm"></i>
                        </button>
                    </div>

                    <textarea id="target-message-input" rows="1" onfocus="activeFocusedInput='single'" placeholder="Type here..." class="flex-1 px-4 py-2.5 bg-white border border-gray-200 rounded-2xl text-xs font-medium focus:outline-none focus:ring-1 focus:ring-[#00a884] resize-none max-h-32 custom-scrollbar"></textarea>
                    
                    <button type="button" onclick="startVoiceRecording()" class="text-gray-500 hover:text-red-500 p-1 text-lg transition" title="Record Voice Note">
                        <i class="fa-solid fa-microphone"></i>
                    </button>

                    <!-- Dual Action Send Buttons -->
                    <div class="flex items-center space-x-1.5 shrink-0">
                        <button type="button" id="btn-send-wubachat" onclick="dispatchDirectSend('wubachat')" class="bg-[#00a884] hover:bg-[#008f6f] text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow flex items-center space-x-1.5" title="Send via Wubachat Free">
                            <span>Send Wubachat</span>
                            <i class="fa-solid fa-paper-plane text-[11px] transform -rotate-12"></i>
                        </button>
                        <button type="button" id="btn-send-whatsapp" onclick="handleWhatsAppSendDecision()" class="bg-[#128c7e] hover:bg-[#075e54] text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow flex items-center space-x-1.5" title="Send via Official WhatsApp">
                            <span>Send WhatsApp</span>
                            <i class="fa-solid fa-paper-plane text-[11px] transform -rotate-12"></i>
                        </button>
                    </div>
                </form>
            </div>
            <!-- 4. Sliding Contact Info Drawer -->
            <div id="contact-info-drawer" class="hidden w-80 lg:w-96 bg-white border-l border-gray-200 flex-col z-30 transition-all duration-300 shadow-xl overflow-y-auto custom-scrollbar">
                <div class="p-4 border-b flex items-center space-x-3 bg-gray-50">
                    <button onclick="closeContactDrawer()" class="p-1 rounded-full hover:bg-gray-200 text-gray-600 transition"><i class="fa-solid fa-xmark text-lg"></i></button>
                    <h3 class="text-sm font-black text-gray-800">Contact info</h3>
                </div>

                <div class="p-6 text-center border-b space-y-3">
                    <div class="w-24 h-24 rounded-full bg-emerald-100 text-emerald-800 font-black text-3xl mx-auto flex items-center justify-center shadow-inner overflow-hidden" id="drawer-avatar">
                        U
                    </div>
                    <div>
                        <div class="flex items-center justify-center space-x-2">
                            <h4 class="text-base font-black text-gray-900" id="drawer-contact-name">User Name</h4>
                            <button type="button" id="drawer-edit-contact-btn" onclick="openEditFromDrawer()" class="text-gray-400 hover:text-[#00a884] text-xs transition" title="Edit Contact Name/Number">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                        </div>
                        <div class="flex items-center justify-center space-x-2 mt-1">
                            <span class="text-xs text-gray-500 font-mono" id="drawer-phone-display">+910000000000</span>
                            <button onclick="copyDrawerPhone()" class="text-[#00a884] hover:text-emerald-700 text-xs font-bold" title="Copy Number"><i class="fa-solid fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="flex justify-center space-x-3 pt-2">
                        <button onclick="triggerCall('voice')" class="p-2.5 rounded-full bg-gray-100 hover:bg-emerald-50 hover:text-[#00a884] transition text-sm"><i class="fa-solid fa-phone"></i></button>
                        <button onclick="triggerCall('video')" class="p-2.5 rounded-full bg-gray-100 hover:bg-emerald-50 hover:text-[#00a884] transition text-sm"><i class="fa-solid fa-video"></i></button>
                        <button onclick="toggleInChatSearch()" class="p-2.5 rounded-full bg-gray-100 hover:bg-emerald-50 hover:text-[#00a884] transition text-sm"><i class="fa-solid fa-magnifying-glass"></i></button>
                    </div>
                </div>

                <div class="p-4 border-b space-y-1">
                    <span class="text-[10px] uppercase font-bold text-gray-400">About</span>
                    <p class="text-xs text-gray-700 font-medium" id="drawer-about-display">Hey there! I am using WhatsApp.</p>
                </div>

                <div class="p-4 border-b space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold text-gray-700">Media, links and docs</span>
                        <span class="text-[11px] font-bold text-gray-400" id="drawer-media-count">0</span>
                    </div>
                    <div class="grid grid-cols-3 gap-1.5" id="drawer-media-grid"></div>
                </div>

                <div class="p-4 space-y-2">
                    <button type="button" id="drawer-block-btn" onclick="toggleDrawerBlockContact()" class="w-full py-2.5 text-red-600 hover:bg-red-50 rounded-2xl text-xs font-bold flex items-center justify-center space-x-2 transition border border-red-200">
                        <i class="fa-solid fa-ban"></i> <span id="drawer-block-text">Block Contact</span>
                    </button>
                </div>
            </div>

        </section>

        <!-- Bulk Blast Tab -->
        <section id="bulk-section" class="flex-1 bg-gray-50 p-6 overflow-y-auto hidden custom-scrollbar">
            <div class="max-w-3xl mx-auto bg-white rounded-3xl p-8 border border-gray-200 shadow-sm space-y-6">
                <div>
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 rounded-xl bg-emerald-50 text-[#009b67] flex items-center justify-center text-sm font-bold"><i class="fa-solid fa-bolt"></i></div>
                        <h2 class="text-lg font-black text-gray-800">1,000 Contact Bulk Blast</h2>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Paste up to 1,000 numbers directly from Excel, CSV, or Notepad with multi-media attachments & translation.</p>
                </div>

                <div id="bulk-alert" class="hidden p-3.5 rounded-2xl text-xs font-semibold"></div>

                <form id="bulk-blast-form" onsubmit="handleBulkBlast(event)" class="space-y-4">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-wider mb-1.5">Campaign Name</label>
                        <input type="text" id="campaign_name" placeholder="e.g. Special Offer Broadcast" required class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-wider mb-1.5">Paste Numbers List (Max 1,000)</label>
                        <textarea id="bulk_numbers" rows="5" oninput="calculateBulkSummary()" placeholder="Paste list here (one number per line or separated by comma)" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl text-xs font-mono focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]"></textarea>
                        
                        <div class="flex justify-between items-center mt-2 px-1 text-xs">
                            <span class="text-gray-500 font-medium">Valid Unique Numbers: <strong id="valid-recipients-count" class="text-[#009b67]">0</strong></span>
                            <span class="text-gray-500 font-medium">Required Balance: <strong id="est-total-cost" class="text-[#009b67]">₹0.00</strong></span>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-[11px] font-bold text-gray-600 uppercase tracking-wider">Attached Files & Screenshots (<span id="bulk-attach-count">0</span>)</label>
                            <label class="cursor-pointer bg-emerald-50 hover:bg-emerald-100 text-[#009b67] px-3 py-1 rounded-xl text-xs font-bold transition flex items-center space-x-1.5 shadow-sm">
                                <i class="fa-solid fa-paperclip"></i>
                                <span>Attach Images, PDF, Docs, Media</span>
                                <input type="file" id="bulk-file-input" multiple onchange="handleBulkFilesSelect(event)" class="hidden">
                            </label>
                        </div>
                        <div id="bulk-attachments-gallery" class="min-h-[50px] p-3 bg-gray-50 border border-dashed border-gray-300 rounded-2xl flex flex-wrap gap-2 items-center">
                            <span class="text-gray-400 text-xs italic" id="bulk-empty-attach-hint">No files attached yet.</span>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-[11px] font-bold text-gray-600 uppercase tracking-wider">Broadcast Message Content</label>
                            <button type="button" onclick="toggleEmojiPanel('bulk', event)" class="text-gray-400 hover:text-amber-500 p-1 text-sm transition" title="Insert Emoji">
                                <i class="fa-regular fa-face-smile text-base"></i>
                            </button>
                        </div>
                        <textarea id="bulk_message" rows="4" onfocus="activeFocusedInput='bulk'" placeholder="Write message text here..." class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]"></textarea>
                    </div>

                    <button type="submit" id="bulk-submit-btn" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-bold py-3.5 rounded-2xl transition duration-200 text-xs tracking-wider uppercase shadow-lg shadow-emerald-700/15 flex items-center justify-center space-x-2">
                        <span>Launch 1,000 Bulk Broadcast Now</span>
                        <i class="fa-solid fa-paper-plane text-xs"></i>
                    </button>
                </form>
            </div>
        </section>
    </main>

    <!-- DYNAMIC TEMPLATE SELECTION LIST MODAL -->
    <div id="template-select-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 select-none modal-wrapper">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <div class="flex items-center space-x-2.5">
                    <span class="w-9 h-9 rounded-2xl bg-emerald-50 text-[#009b67] flex items-center justify-center text-base font-bold">
                        <i class="fa-solid fa-list-check"></i>
                    </span>
                    <div>
                        <h3 class="text-sm font-extrabold text-gray-800">Select WhatsApp Template</h3>
                        <p class="text-[10px] text-gray-400">Approved Meta templates for active outbound dispatch</p>
                    </div>
                </div>
                <button type="button" onclick="closeTemplateSelectModal()" class="text-gray-400 hover:text-gray-600 text-base">✕</button>
            </div>

            <div class="space-y-2 max-h-60 overflow-y-auto custom-scrollbar" id="template-list-container">
                <div onclick="selectTemplateAndOpen('survey_alert')" class="p-3 bg-gray-50 hover:bg-emerald-50 border border-gray-200 hover:border-emerald-300 rounded-2xl cursor-pointer transition flex items-center justify-between">
                    <div>
                        <h4 class="text-xs font-bold text-gray-900">Paramount Inspection Link Template</h4>
                        <p class="text-[10px] text-gray-500">Category: UTILITY | 3 Body Variables</p>
                    </div>
                    <span class="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2.5 py-1 rounded-xl">Select</span>
                </div>
            </div>

            <button type="button" onclick="closeTemplateSelectModal()" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-xs transition">Cancel</button>
        </div>
    </div>

    <!-- SURVEY TEMPLATE POPUP MODAL -->
    <div id="survey-template-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 select-none modal-wrapper">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <div class="flex items-center space-x-2.5">
                    <span class="w-9 h-9 rounded-2xl bg-emerald-50 text-[#009b67] flex items-center justify-center text-base font-bold">
                        <i class="fa-solid fa-file-invoice"></i>
                    </span>
                    <div>
                        <h3 class="text-sm font-extrabold text-gray-800">Paramount Inspection Link Template</h3>
                        <p class="text-[10px] text-gray-400">24-hour free window is closed. 3 template variables required.</p>
                    </div>
                </div>
                <button type="button" onclick="closeSurveyTemplateModal()" class="text-gray-400 hover:text-gray-600 text-base">✕</button>
            </div>

            <form id="survey-modal-form" onsubmit="handleSurveyModalSubmit(event)" class="space-y-3">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">1. Claim Number (Variable 1)</label>
                    <input type="text" id="modal-tpl-claim" placeholder="e.g. 420812/31/2027/PRTLC/88039120" required class="w-full px-3.5 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs font-mono focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">2. Vehicle Number (Variable 2)</label>
                    <input type="text" id="modal-tpl-vehicle" placeholder="e.g. DL01AB1234" required class="w-full px-3.5 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs uppercase font-mono focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">3. Inspection Link (Variable 3)</label>
                    <input type="url" id="modal-tpl-link" placeholder="https://sage-be.paramountservices.co.in/api/..." required class="w-full px-3.5 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs font-mono focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                </div>

                <div class="p-2.5 bg-amber-50 rounded-xl border border-amber-200 text-amber-900 text-[10px] flex items-center">
                    <i class="fa-solid fa-circle-info mr-1.5 text-xs text-amber-600 shrink-0"></i>
                    <span>Sending this template will cost <strong>₹0.50 (1 Credit)</strong> from your wallet. Exactly 3 parameters are mapped.</span>
                </div>

                <div class="flex space-x-2.5 pt-1">
                    <button type="button" onclick="closeSurveyTemplateModal()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-xs transition">Cancel</button>
                    <button type="submit" id="survey-modal-submit-btn" class="flex-1 bg-[#128c7e] hover:bg-[#075e54] text-white font-bold py-2.5 rounded-xl text-xs transition shadow flex items-center justify-center space-x-1.5">
                        <span>Send Official WhatsApp</span> <i class="fa-solid fa-paper-plane text-[11px]"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT CONTACT MODAL (NAME & PHONE) -->
    <div id="edit-contact-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex justify-between items-center">
                <h3 class="text-base font-extrabold text-gray-800">Edit Contact Details</h3>
                <button onclick="closeEditContactModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form onsubmit="handleUpdateContact(event)" class="space-y-3.5">
                <input type="hidden" id="edit-contact-id" value="">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Contact Name</label>
                    <input type="text" id="edit-contact-name" required class="w-full px-3.5 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Mobile Number</label>
                    <div class="flex items-center rounded-xl bg-gray-50 border border-gray-200 overflow-hidden focus-within:bg-white focus-within:ring-1 focus-within:ring-[#00a884]">
                        <span class="px-3 py-2 bg-gray-100 border-r border-gray-200 text-xs font-extrabold text-gray-600 select-none">+91</span>
                        <input type="tel" id="edit-contact-phone" required class="w-full px-3 py-2 bg-transparent text-xs font-mono font-semibold text-gray-800 focus:outline-none">
                    </div>
                </div>
                <button type="submit" id="update-contact-btn" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-bold py-2.5 rounded-xl text-xs shadow transition">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- BILLING AUDIT & WALLET TRANSACTIONS MODAL -->
    <div id="billing-audit-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-3xl w-full flex flex-col max-h-[85vh] shadow-2xl border overflow-hidden">
            <div class="px-6 py-4 border-b flex justify-between items-center bg-gray-50">
                <h3 class="text-sm font-extrabold text-gray-800 flex items-center">
                    <i class="fa-solid fa-receipt text-[#009b67] mr-2"></i> Billing Logs & Wallet History
                </h3>
                <button onclick="closeBillingAuditModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="p-4 bg-gray-50 border-b flex flex-wrap items-center justify-between gap-3 text-xs">
                <div class="flex items-center space-x-2">
                    <label class="font-bold text-gray-600">From:</label>
                    <input type="date" id="billing-from-date" value="<?= date('Y-m-01') ?>" class="px-2.5 py-1 bg-white border rounded-xl">
                    <label class="font-bold text-gray-600 ml-2">To:</label>
                    <input type="date" id="billing-to-date" value="<?= date('Y-m-d') ?>" class="px-2.5 py-1 bg-white border rounded-xl">
                    <button onclick="loadBillingAuditLogs()" class="px-3 py-1 bg-[#00a884] text-white font-bold rounded-xl shadow-xs">Filter</button>
                </div>
            </div>

            <!-- Billing Tabs -->
            <div class="flex border-b border-gray-200 bg-gray-100 px-4 pt-2 text-xs font-bold">
                <button onclick="switchBillingTab('messages')" id="billing-tab-msg-btn" class="px-4 py-2 bg-white text-[#009b67] rounded-t-xl border-t border-x border-gray-200 shadow-xs">Message Usage Logs</button>
                <button onclick="switchBillingTab('wallet')" id="billing-tab-wallet-btn" class="px-4 py-2 text-gray-600 rounded-t-xl hover:bg-gray-200 transition">Wallet Top-up & Receipts</button>
            </div>

            <div class="flex-1 overflow-y-auto custom-scrollbar p-4">
                <div id="billing-messages-view">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b text-gray-500 font-bold uppercase text-[10px]">
                                <th class="py-2 px-3">Date</th>
                                <th class="py-2 px-3">Recipient</th>
                                <th class="py-2 px-3">Type</th>
                                <th class="py-2 px-3">Route</th>
                                <th class="py-2 px-3 text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody id="billing-table-body" class="divide-y"></tbody>
                    </table>
                </div>

                <div id="billing-wallet-view" class="hidden">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b text-gray-500 font-bold uppercase text-[10px]">
                                <th class="py-2 px-3">Date</th>
                                <th class="py-2 px-3">Transaction ID / UTR</th>
                                <th class="py-2 px-3">Payee</th>
                                <th class="py-2 px-3 text-right">Amount</th>
                                <th class="py-2 px-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="billing-wallet-table-body" class="divide-y"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- PDF RECEIPT POPUP MODAL -->
    <div id="pdf-receipt-modal" class="fixed inset-0 bg-black/70 backdrop-blur-md hidden items-center justify-center z-[9999] p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border space-y-4">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-sm font-black text-gray-800 flex items-center">
                    <i class="fa-solid fa-file-invoice text-[#009b67] mr-2"></i> Payment Receipt - <span id="receipt-id-title">WUBA-REC</span>
                </h3>
                <div class="flex items-center space-x-2">
                    <button onclick="downloadReceiptPDF()" class="bg-[#00a884] hover:bg-[#008f6f] text-white px-3 py-1.5 rounded-xl text-xs font-bold shadow flex items-center space-x-1.5 transition">
                        <i class="fa-solid fa-download"></i> <span>Download PDF</span>
                    </button>
                    <button onclick="closePdfReceiptModal()" class="text-gray-400 hover:text-gray-600 text-base p-1">✕</button>
                </div>
            </div>

            <div id="receipt-printable-area" class="bg-white border border-gray-200 rounded-2xl p-6 space-y-4 text-xs font-medium text-gray-700 shadow-sm">
                <div class="text-center border-b border-gray-200 pb-4 space-y-1">
                    <div class="flex items-center justify-center space-x-2">
                        <img src="/assets/logo.png" alt="Wubachat" style="height: 80px; width: auto; object-fit: contain;">
                    </div>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest font-bold mt-1">Official Payment Receipt</p>
                </div>

                <div class="space-y-2.5 text-xs">
                    <div class="flex justify-between py-1.5 border-b border-gray-100">
                        <span class="text-gray-500">Receipt ID:</span>
                        <strong class="font-mono text-gray-900" id="rcpt-receipt-id">WUBA-REC-100005</strong>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-gray-100">
                        <span class="text-gray-500">Transaction Date:</span>
                        <strong class="font-mono text-gray-900" id="rcpt-date">2026-09-15 07:06:43</strong>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-gray-100">
                        <span class="text-gray-500">Payer (User):</span>
                        <strong class="text-gray-900" id="rcpt-payer"><?= htmlspecialchars($display_name) ?> (<?= htmlspecialchars($user_phone) ?>)</strong>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-gray-100">
                        <span class="text-gray-500">Payee Entity:</span>
                        <strong class="text-gray-900">Wubachat (Business Entity)</strong>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-gray-100">
                        <span class="text-gray-500">Payment Method:</span>
                        <strong class="text-gray-900">UPI / Bank Transfer</strong>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-gray-100">
                        <span class="text-gray-500">Reference ID / UTR:</span>
                        <strong class="font-mono text-[#00a884]" id="rcpt-utr">UTR_REF_5</strong>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-gray-100">
                        <span class="text-gray-500">Description:</span>
                        <span>Wallet Top-up & Credit Recharge</span>
                    </div>
                    <div class="flex justify-between py-2 text-sm font-black border-t border-gray-300">
                        <span>Amount Paid:</span>
                        <span class="text-[#009b67]" id="rcpt-amount">₹100.00</span>
                    </div>
                    <div class="flex justify-between py-1.5">
                        <span class="text-gray-500">Status:</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 uppercase">Successful</span>
                    </div>
                </div>

                <div class="text-[10px] text-center text-gray-400 pt-3 border-t border-gray-200">
                    This is an electronically generated receipt. No signature required.<br>For support, contact support@wubachat.com.
                </div>
            </div>
        </div>
    </div>

    <div id="blocked-users-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-md w-full flex flex-col max-h-[85vh] shadow-2xl border overflow-hidden">
            <div class="px-6 py-4 border-b flex justify-between items-center bg-gray-50">
                <h3 class="text-sm font-extrabold text-gray-800 flex items-center">
                    <i class="fa-solid fa-user-slash text-red-500 mr-2"></i> Blocked Contacts
                </h3>
                <button onclick="closeBlockedUsersModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar p-4 divide-y divide-gray-100" id="blocked-users-list-container">
                <p class="text-center py-4 text-xs text-gray-400">Loading blocked list...</p>
            </div>
        </div>
    </div>

    <div id="settings-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border space-y-4 max-h-[90vh] overflow-y-auto custom-scrollbar">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-base font-extrabold text-gray-800">Account Profile & Settings</h3>
                <button onclick="closeSettingsModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form onsubmit="handleSaveProfileUpdate(event)" class="space-y-3 bg-gray-50 p-4 rounded-2xl border border-gray-100">
                <h4 class="text-xs font-black text-gray-800 uppercase">My Profile Details</h4>
                <div class="flex items-center space-x-3">
                    <div class="relative w-16 h-16 rounded-full bg-emerald-100 text-[#00a884] font-black text-xl flex items-center justify-center overflow-hidden border border-gray-200">
                        <img id="settings-avatar-preview" src="<?= htmlspecialchars($user_avatar !== '' ? $user_avatar : $avatar_placeholder, ENT_QUOTES, 'UTF-8') ?>" class="w-full h-full object-cover">
                        <label class="absolute inset-0 bg-black/30 hover:bg-black/50 cursor-pointer flex items-center justify-center text-white transition">
                            <i class="fa-solid fa-camera text-sm"></i>
                            <input type="file" id="settings-profile-file" accept="image/*" onchange="previewSelectedAvatar(this)" class="hidden">
                        </label>
                    </div>
                    <div class="flex-1">
                        <span class="text-xs font-bold text-gray-700">Profile Photo</span>
                        <div class="flex items-center space-x-2 mt-1">
                            <label for="settings-profile-file" class="text-[10px] text-[#00a884] font-bold cursor-pointer hover:underline">Change Photo</label>
                            <span class="text-gray-300">•</span>
                            <button type="button" onclick="handleRemoveProfilePic()" class="text-[10px] text-red-500 font-bold hover:underline">Remove</button>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Display Name</label>
                    <input type="text" id="settings-profile-name" value="<?= htmlspecialchars($display_name) ?>" required class="w-full px-3 py-1.5 bg-white border rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">About / Bio</label>
                    <input type="text" id="settings-profile-bio" value="<?= htmlspecialchars($user_bio) ?>" required class="w-full px-3 py-1.5 bg-white border rounded-xl text-xs">
                </div>
                <button type="submit" id="profile-update-btn" class="w-full bg-[#00a884] text-white font-bold py-2 rounded-xl text-xs shadow-xs">Update Profile</button>
            </form>
            <!-- Wubachat Smart Dispatcher Extension Section -->
            <div class="mt-4 bg-emerald-50/70 border border-emerald-200/80 p-4 rounded-2xl space-y-2.5">
                <div class="flex items-center space-x-2.5">
                    <span class="w-8 h-8 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-sm shadow-sm">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </span>
                    <div>
                        <h4 class="text-xs font-black text-gray-800 uppercase tracking-wide">Wubachat Smart Dispatcher</h4>
                        <p class="text-[10px] text-gray-500 font-medium">1-Click Auto Fill & WhatsApp Template Tool</p>
                    </div>
                </div>

                <p class="text-[11px] text-gray-600 leading-relaxed">
                    Portal par <strong>⚡ Send via Wubachat</strong> button activate karne ke liye niche se tool download karein.
                </p>

                <div class="pt-1">
                    <a href="download_assistant.php" class="w-full bg-[#128c7e] hover:bg-[#075e54] text-white font-bold py-2.5 px-3 rounded-xl text-xs shadow-sm transition flex items-center justify-center space-x-2 text-center decoration-none">
                        <i class="fa-solid fa-download"></i>
                        <span>Download Smart Dispatcher (.zip)</span>
                    </a>
                </div>

                <div class="text-[10px] text-gray-500 bg-white/80 p-2.5 rounded-xl border border-emerald-100 space-y-1">
                    <div class="font-bold text-gray-700">Setup Kaise Karein:</div>
                    <div>1. Zip open karke <code>Install_Assistant.bat</code> run karein.</div>
                    <div>2. Chrome me <code>Load unpacked</code> dabakar folder select karein.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Row Dynamic Menu -->
    <div id="contact-row-menu" class="hidden fixed bg-white rounded-2xl shadow-xl border border-gray-100 py-1.5 z-50 text-xs w-48">
        <button onclick="triggerRowAction('unread')" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center space-x-2"><i class="fa-solid fa-envelope text-gray-400"></i><span>Mark as unread</span></button>
        <button id="row-archive-btn" onclick="triggerRowAction('archive')" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center space-x-2"><i class="fa-solid fa-box-archive text-gray-400"></i><span>Archive chat</span></button>
        <button id="row-important-btn" onclick="triggerRowAction('important')" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center space-x-2"><i class="fa-solid fa-star text-amber-400"></i><span>Mark Important</span></button>
        <hr class="my-1 border-gray-100">
        <button onclick="triggerRowAction('delete_conversation')" class="w-full text-left px-4 py-2 hover:bg-red-50 text-red-600 flex items-center space-x-2"><i class="fa-solid fa-trash-can text-red-500"></i><span>Delete Conversation</span></button>
        <button onclick="triggerRowAction('block')" class="w-full text-left px-4 py-2 hover:bg-red-50 text-red-600 flex items-center space-x-2"><i class="fa-solid fa-ban text-red-500"></i><span>Block Contact</span></button>
    </div>

    <div id="whatsapp-warning-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 select-none modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 border-b border-gray-100 pb-3">
                <span class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg font-bold"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div>
                    <h3 class="text-sm font-black text-gray-800">Paid WhatsApp Cloud API</h3>
                    <p class="text-[10px] text-gray-400">Balance Deduction Notice</p>
                </div>
            </div>
            <div class="space-y-2.5 text-xs text-gray-600 leading-relaxed">
                <p>You are dispatching this message via the <strong>Official WhatsApp Cloud API</strong> to <strong id="warn-recipient-display" class="font-mono text-gray-800"></strong>.</p>
                <div class="p-3 bg-amber-50 rounded-2xl border border-amber-200 text-amber-900 text-[11px]">
                    <i class="fa-solid fa-circle-info mr-1"></i> This transmission will deduct <strong>1 Credit (₹0.50)</strong> from your wallet balance.
                </div>
            </div>
            <div class="flex space-x-2.5 pt-2">
                <button type="button" onclick="closeWhatsAppWarningModal()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-xs transition">Cancel</button>
                <button type="button" onclick="confirmAndSendWhatsAppPaid()" class="flex-1 bg-[#128c7e] hover:bg-[#075e54] text-white font-bold py-2.5 rounded-xl text-xs transition shadow flex items-center justify-center space-x-1">
                    <span>Confirm & Send</span> <i class="fa-solid fa-check text-[10px]"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="logout-confirmation-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 select-none modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4 text-center">
            <div class="w-12 h-12 bg-red-50 text-red-600 rounded-full flex items-center justify-center mx-auto text-xl shadow-inner"><i class="fa-solid fa-power-off"></i></div>
            <div>
                <h3 class="text-base font-extrabold text-gray-800">Confirm Logout</h3>
                <p class="text-xs text-gray-500 mt-1">Do you want to logout this account?</p>
            </div>
            <div class="flex space-x-2.5 pt-2">
                <button type="button" onclick="closeLogoutModal()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-xs">Cancel</button>
                <a href="logout.php" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-xs flex items-center justify-center"><span>Yes, Logout</span></a>
            </div>
        </div>
    </div>

    <div id="message-info-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex justify-between items-center border-b border-gray-100 pb-3">
                <h3 class="text-sm font-extrabold text-gray-800 flex items-center"><i class="fa-solid fa-circle-info text-[#00a884] mr-2"></i> Message info</h3>
                <button onclick="closeMessageInfoModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="p-3 bg-gray-50 rounded-2xl border border-gray-100 space-y-1">
                <span class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Message Content</span>
                <p id="msg-info-preview-text" class="text-xs text-gray-700 font-medium whitespace-pre-wrap max-h-24 overflow-y-auto custom-scrollbar"></p>
            </div>
            <div class="space-y-3 divide-y divide-gray-100 text-xs">
                <div class="flex items-center justify-between pt-1">
                    <div class="flex items-center space-x-2"><i class="fa-solid fa-check-double text-[#53bdeb]"></i><span class="font-bold text-gray-700">Read</span></div>
                    <span id="msg-info-read-time" class="text-gray-500 font-mono text-[11px]">-</span>
                </div>
                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center space-x-2"><i class="fa-solid fa-check-double text-gray-400"></i><span class="font-bold text-gray-700">Delivered</span></div>
                    <span id="msg-info-delivered-time" class="text-gray-500 font-mono text-[11px]">-</span>
                </div>
                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center space-x-2"><i class="fa-solid fa-check text-gray-400"></i><span class="font-bold text-gray-700">Sent</span></div>
                    <span id="msg-info-sent-time" class="text-gray-500 font-mono text-[11px]">-</span>
                </div>
                <div class="flex items-center justify-between pt-2">
                    <span class="font-bold text-gray-700">Status</span>
                    <span id="msg-info-status-badge" class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800">Sent</span>
                </div>
            </div>
            <button type="button" onclick="closeMessageInfoModal()" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-xs transition">Close</button>
        </div>
    </div>

    <div id="forward-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-md w-full flex flex-col max-h-[85vh] shadow-2xl border overflow-hidden">
            <div class="px-6 py-4 border-b flex justify-between items-center bg-gray-50">
                <h3 class="text-sm font-extrabold text-gray-800">Forward message to</h3>
                <button onclick="closeForwardModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="p-3 border-b">
                <input type="text" id="forward-search-input" oninput="renderForwardContactsList()" placeholder="Search contacts..." class="w-full px-3 py-1.5 bg-[#f0f2f5] border-0 rounded-xl text-xs focus:outline-none focus:ring-1 focus:ring-[#00a884]">
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar p-3 space-y-1 divide-y divide-gray-50" id="forward-contacts-container"></div>
            <div class="p-3.5 border-t bg-gray-50 flex justify-between items-center">
                <span class="text-xs font-bold text-gray-500" id="forward-selected-count">0 contacts selected</span>
                <button id="forward-submit-btn" onclick="dispatchForwardMessages()" class="px-5 py-2 bg-[#00a884] hover:bg-[#008f6f] text-white rounded-xl text-xs font-bold shadow flex items-center space-x-1.5 transition">
                    <span id="forward-btn-text">Send</span> <i id="forward-btn-icon" class="fa-solid fa-paper-plane text-[11px]"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="gallery-lightbox" class="fixed inset-0 bg-black/95 backdrop-blur-md hidden flex-col justify-between z-[99999] p-4 select-none">
        <div class="flex justify-between items-center text-white px-4 py-2 border-b border-gray-800">
            <span class="text-xs font-mono text-gray-400" id="gallery-image-index">Image 1 of 1</span>
            <div class="flex items-center space-x-4 text-base">
                <a id="gallery-download-btn" href="#" download class="hover:text-emerald-400 transition" title="Download Image"><i class="fa-solid fa-download"></i></a>
                <button onclick="forwardSingleGalleryImage()" class="hover:text-blue-400 transition" title="Forward Image"><i class="fa-solid fa-share"></i></button>
                <button onclick="closeGalleryLightbox()" class="hover:text-red-400 transition text-lg" title="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div class="flex-1 flex items-center justify-between px-6 relative">
            <button onclick="prevGalleryImage()" class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center text-lg transition"><i class="fa-solid fa-chevron-left"></i></button>
            <div class="max-w-4xl max-h-[75vh] flex items-center justify-center">
                <img id="gallery-preview-img" src="" alt="Photo" class="max-h-[72vh] max-w-full rounded-xl object-contain shadow-2xl">
            </div>
            <button onclick="nextGalleryImage()" class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center text-lg transition"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
    </div>

    <!-- Translation Popover Box -->
    <div id="translation-popover" class="hidden fixed bg-white rounded-2xl shadow-2xl border border-gray-200 p-3.5 z-[99999] w-[300px] max-w-[90vw] space-y-2 text-xs">
        <div class="flex justify-between items-center border-b border-gray-100 pb-1.5">
            <span class="font-extrabold text-[#008069] flex items-center"><i class="fa-solid fa-language mr-1.5"></i> Translated Message</span>
            <button onclick="closeTranslationPopover()" class="text-gray-400 hover:text-gray-600 text-sm">✕</button>
        </div>
        <p id="translation-popover-text" class="text-gray-700 whitespace-pre-wrap leading-relaxed select-all bg-gray-50 p-2.5 rounded-xl border border-gray-100 font-medium"></p>
        <div class="flex justify-end space-x-2 pt-1">
            <button onclick="copyTranslatedText()" class="bg-emerald-50 hover:bg-emerald-100 text-[#008069] px-3 py-1 rounded-lg font-bold flex items-center space-x-1 shadow-sm">
                <i class="fa-solid fa-copy"></i> <span>Copy Text</span>
            </button>
        </div>
    </div>

    <div id="calling-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-gray-900 rounded-3xl max-w-lg w-full p-6 text-center space-y-5 text-white shadow-2xl border border-gray-800">
            <div class="space-y-1">
                <h3 class="text-base font-extrabold" id="call-title-text">Calling Wubachat Client...</h3>
                <p class="text-xs text-emerald-400 font-mono" id="call-status-timer">Connecting via Peer Portal...</p>
            </div>
            <div class="relative w-full h-64 bg-black rounded-2xl overflow-hidden flex items-center justify-center border border-gray-800">
                <video id="local-call-video" autoplay muted class="w-full h-full object-cover"></video>
                <div id="audio-call-avatar" class="hidden flex-col items-center">
                    <div class="w-20 h-20 bg-emerald-600 rounded-full flex items-center justify-center text-2xl font-bold mb-2 animate-pulse">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <span class="text-xs font-semibold text-gray-300">Wubachat Live Voice</span>
                </div>
            </div>
            <div class="flex justify-center space-x-4">
                <button onclick="endActiveCall()" class="w-12 h-12 bg-red-600 hover:bg-red-700 rounded-full flex items-center justify-center text-lg shadow-lg transition" title="End Call">
                    <i class="fa-solid fa-phone-slash"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="quick-reaction-bar" class="hidden fixed bg-white rounded-full shadow-2xl border border-gray-200 px-3 py-1.5 z-[9999] flex items-center space-x-2 text-xl">
        <button onclick="dispatchReaction('👍')" class="hover:scale-125 transition">👍</button>
        <button onclick="dispatchReaction('❤️')" class="hover:scale-125 transition">❤️</button>
        <button onclick="dispatchReaction('😂')" class="hover:scale-125 transition">😂</button>
        <button onclick="dispatchReaction('😮')" class="hover:scale-125 transition">😮</button>
        <button onclick="dispatchReaction('😢')" class="hover:scale-125 transition">😢</button>
        <button onclick="dispatchReaction('🙏')" class="hover:scale-125 transition">🙏</button>
    </div>

    <div id="emoji-panel" class="hidden fixed bottom-20 left-16 bg-white rounded-3xl shadow-2xl border border-gray-200 z-[9999] w-80 max-h-80 flex flex-col overflow-hidden">
        <div class="p-2.5 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
            <span class="text-xs font-bold text-gray-700">All WhatsApp Emojis</span>
            <div class="space-x-1 text-xs">
                <button type="button" onclick="loadEmojiCategory('smileys')" class="hover:bg-gray-200 p-1 rounded">😀</button>
                <button type="button" onclick="loadEmojiCategory('gestures')" class="hover:bg-gray-200 p-1 rounded">👍</button>
                <button type="button" onclick="loadEmojiCategory('animals')" class="hover:bg-gray-200 p-1 rounded">🐶</button>
                <button type="button" onclick="loadEmojiCategory('food')" class="hover:bg-gray-200 p-1 rounded">🍔</button>
                <button type="button" onclick="loadEmojiCategory('objects')" class="hover:bg-gray-200 p-1 rounded">💡</button>
                <button type="button" onclick="loadEmojiCategory('symbols')" class="hover:bg-gray-200 p-1 rounded">❤️</button>
            </div>
            <button type="button" onclick="document.getElementById('emoji-panel').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xs ml-1">✕</button>
        </div>
        <div class="p-3 grid grid-cols-8 gap-1 text-lg overflow-y-auto custom-scrollbar" id="emoji-grid-container"></div>
    </div>

    <!-- RECHARGE MODAL WITH LIVE AMOUNT LINKED QR CODE & MIN ₹100 LIMIT -->
    <div id="recharge-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4 text-center">
            <div class="flex justify-between items-center border-b border-gray-100 pb-2">
                <h3 class="text-sm font-extrabold text-gray-800">Add Balance via UPI</h3>
                <button onclick="closeRechargeModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="p-3 bg-gray-50 rounded-2xl border border-gray-100 flex flex-col items-center">
                <img id="upi-qr-image" src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=upi://pay?pa=<?= urlencode($admin_upi) ?>%26pn=<?= urlencode($admin_payee) ?>%26am=100.00%26cu=INR" alt="Wubachat QR" class="w-40 h-40 rounded-xl border border-gray-200 shadow-sm">
                <span class="text-[11px] text-[#009b67] font-extrabold mt-2">UPI ID: <?= htmlspecialchars($admin_upi) ?></span>
            </div>
            <form onsubmit="handleRechargeSubmit(event)" class="space-y-3 text-left">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Recharge Amount (₹ Min: 100)</label>
                    <input type="number" id="recharge-amount" min="100" step="1" value="100" required oninput="validateAndSyncQR(this.value)" class="w-full px-3.5 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-800 focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                    <span id="recharge-error-msg" class="hidden text-[10px] text-red-600 font-bold mt-1 block">⚠️ Minimum recharge amount is 100. You cannot recharge below this amount.</span>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">UTR / Ref Number</label>
                    <input type="text" id="recharge-utr" placeholder="12-digit UTR" required class="w-full px-3.5 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                </div>
                <button type="submit" id="recharge-submit-btn" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-bold py-3 rounded-xl text-xs uppercase shadow transition">Submit & Credit</button>
            </form>
        </div>
    </div>

    <div id="contact-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex justify-between items-center">
                <h3 class="text-base font-extrabold text-gray-800">Save Contact</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form onsubmit="handleSaveContact(event)" class="space-y-3.5">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Name</label>
                    <input type="text" id="modal-contact-name" placeholder="Enter Full Name" required class="w-full px-3.5 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#00a884]">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Phone Number</label>
                    <div class="flex items-center rounded-xl bg-gray-50 border border-gray-200 overflow-hidden focus-within:bg-white focus-within:ring-1 focus-within:ring-[#00a884]">
                        <span class="px-3 py-2 bg-gray-100 border-r border-gray-200 text-xs font-extrabold text-gray-600 select-none">+91</span>
                        <input type="tel" id="modal-contact-phone" placeholder="9876543210" required class="w-full px-3 py-2 bg-transparent text-xs font-mono font-semibold text-gray-800 focus:outline-none">
                    </div>
                    <span class="text-[10px] text-gray-400 mt-1 block">Type 10-digit number. Country code (+91) handles automatically.</span>
                </div>
                <button type="submit" id="save-contact-btn" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-bold py-2.5 rounded-xl text-xs shadow transition">Save Contact</button>
            </form>
        </div>
    </div>

    <div id="contact-book-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-xl w-full flex flex-col max-h-[85vh] shadow-2xl border overflow-hidden">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="text-sm font-extrabold text-gray-800">Saved Contacts Directory</h3>
                <button onclick="closeContactBookModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="p-3.5 bg-gray-50 border-b">
                <input type="text" id="contact-book-search" oninput="renderContactBookTable()" placeholder="Search directory..." class="w-full px-3 py-1.5 bg-white border rounded-xl text-xs focus:outline-none focus:ring-1 focus:ring-[#00a884]">
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar p-4">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b text-gray-500 font-bold uppercase text-[10px]">
                            <th class="py-2.5 px-3">Name</th>
                            <th class="py-2.5 px-3">Phone</th>
                            <th class="py-2.5 px-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="contact-book-table-body" class="divide-y"></tbody>
                </table>
            </div>
            <div class="p-3.5 border-t bg-gray-50 flex justify-end">
                <button onclick="closeContactBookModal()" class="px-4 py-1.5 bg-gray-200 text-gray-700 font-bold rounded-xl text-xs">Close</button>
            </div>
        </div>
    </div>

    <div id="import-contacts-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex justify-between items-center border-b border-gray-100 pb-2">
                <h3 class="text-sm font-extrabold text-gray-800">Import Contacts List</h3>
                <button onclick="closeImportContactsModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form onsubmit="handleImportContactsSubmit(event)" class="space-y-4">
                <input type="file" id="contacts-csv-file" accept=".csv,.txt" required class="w-full text-xs text-gray-500">
                <button type="submit" id="import-contacts-btn" class="w-full bg-[#00a884] text-white font-bold py-3 rounded-xl text-xs shadow">Import</button>
            </form>
        </div>
    </div>

    <div id="import-backup-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-gray-100 space-y-4">
            <div class="flex justify-between items-center border-b border-gray-100 pb-2">
                <h3 class="text-sm font-extrabold text-gray-800">Restore WhatsApp Chat (.txt)</h3>
                <button onclick="closeImportBackupModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form onsubmit="handleImportBackupSubmit(event)" class="space-y-3">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Target Mobile Number</label>
                    <input type="text" id="backup-target-phone" required class="w-full px-3.5 py-2 bg-gray-50 border rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Select .txt File</label>
                    <input type="file" id="backup-chat-file" accept=".txt" required class="w-full text-xs text-gray-500">
                </div>
                <button type="submit" id="import-backup-btn" class="w-full bg-[#00a884] text-white font-bold py-3 rounded-xl text-xs shadow">Restore</button>
            </form>
        </div>
    </div>

    <div id="meta-connect-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-md w-full p-7 shadow-2xl border border-gray-100 space-y-5">
            <div class="flex justify-between items-center border-b border-gray-100 pb-3">
                <h3 class="text-base font-extrabold text-gray-800">Connect Meta WhatsApp</h3>
                <button onclick="closeMetaConnectModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="text-center space-y-3 py-4 bg-blue-50/60 p-5 rounded-2xl border border-blue-100">
                <div class="w-12 h-12 bg-blue-600 text-white rounded-full flex items-center justify-center mx-auto text-xl shadow-md"><i class="fa-brands fa-whatsapp"></i></div>
                <h4 class="text-xs font-black text-gray-800 uppercase tracking-wide">Instant WhatsApp Setup</h4>
                <p class="text-[11px] text-gray-500">Connect your WhatsApp number securely using Meta's official Embedded Signup flow.</p>
                <button type="button" onclick="launchWhatsAppEmbeddedSignup()" class="w-full bg-[#1877f2] hover:bg-[#166fe5] text-white font-bold py-3 px-4 rounded-xl text-xs shadow-md transition flex items-center justify-center space-x-2">
                    <i class="fa-brands fa-facebook text-base"></i><span>Continue with Facebook / Embedded Signup</span>
                </button>
            </div>

            <div class="relative flex py-1 items-center">
                <div class="flex-grow border-t border-gray-200"></div>
                <span class="flex-shrink mx-4 text-gray-400 text-[11px] font-bold uppercase">Or Manual Connection</span>
                <div class="flex-grow border-t border-gray-200"></div>
            </div>

            <form id="meta-send-form" onsubmit="handleMetaSendOTP(event)" class="space-y-3">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Mobile Number</label>
                    <input type="tel" id="meta-connect-phone" maxlength="10" placeholder="10-digit number" required class="w-full px-4 py-2.5 bg-gray-50 border rounded-xl text-xs">
                </div>
                <button type="submit" id="meta-send-btn" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-bold py-3 rounded-xl text-xs uppercase shadow">Connect</button>
            </form>
            <form id="meta-verify-form" onsubmit="handleMetaVerifyOTP(event)" class="space-y-3 hidden">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">6-Digit Code</label>
                    <input type="text" id="meta-otp-input" maxlength="6" required class="w-full text-center text-xl font-black py-2 bg-gray-50 border rounded-xl">
                </div>
                <button type="submit" id="meta-verify-btn" class="w-full bg-[#00a884] hover:bg-[#008f6f] text-white font-bold py-3 rounded-xl text-xs uppercase shadow">Verify</button>
            </form>
        </div>
    </div>

    <div id="camera-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden items-center justify-center z-50 p-4 modal-wrapper">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4 text-center">
            <h3 class="text-sm font-bold text-gray-800">Take Photo</h3>
            <video id="camera-video-feed" autoplay class="w-full rounded-2xl bg-black h-60 object-cover"></video>
            <canvas id="camera-canvas" class="hidden"></canvas>
            <div class="flex justify-center space-x-3">
                <button type="button" onclick="closeCameraModal()" class="px-4 py-2 bg-gray-200 rounded-xl text-xs font-bold">Cancel</button>
                <button type="button" onclick="snapAndSendCameraPhoto()" class="px-6 py-2 bg-[#00a884] text-white rounded-xl text-xs font-bold shadow">Capture & Send</button>
            </div>
        </div>
    </div>

    <!-- Facebook SDK Script for Embedded Signup -->
    <script>
        window.fbAsyncInit = function() {
            FB.init({
                appId      : '1944154479609797',
                cookie     : true,
                xfbml      : true,
                version    : 'v22.0'
            });
        };

        function launchWhatsAppEmbeddedSignup() {
            FB.login(function(response) {
                if (response.authResponse) {
                    const accessToken = response.authResponse.accessToken;
                    FB.api('/me/accounts', function(resp) {
                        submitEmbeddedSignupData({
                            access_token: accessToken,
                            phone_number_id: 'auto_fetched_via_graph',
                            waba_id: 'auto_fetched_via_graph'
                        });
                    });
                } else {
                    alert('Facebook login was cancelled.');
                }
            }, {
                scope: 'whatsapp_business_management,whatsapp_business_messaging', 
                config_id: '1800909287762584'
            });
        }

        async function submitEmbeddedSignupData(payload) {
            const fd = new FormData();
            fd.append('action', 'embedded_signup');
            fd.append('access_token', payload.access_token);
            fd.append('phone_number_id', payload.phone_number_id);
            fd.append('waba_id', payload.waba_id);

            try {
                const res = await fetch('api/meta_connect.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status) {
                    alert(data.message || 'WhatsApp number linked successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Linking failed.');
                }
            } catch (err) {
                alert('Network error while saving Embedded Signup configuration.');
            }
        }
    </script>
    <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>

    <!-- Core App Engine JS -->
    <script>
        const emojiCategories = {
            smileys: ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','💩','👻','💀','☠️','👽','👾','🎃','😺','😸','😹','😻','😼','😽','🙀','😿','😾'],
            gestures: ['👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','🧠','🦷','🦴','👀','👁️️','👅','👄','💋','🩸'],
            animals: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐽','🐸','🐵','🐒','🦍','🦧','🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐜','🦟','🦗','🕷️','🕸️','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦞','🦀','🦐','🐠','🐟','🐬','🐳','🐋','🦈','🐅','🐆','🦓','🐘','🦛','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🐐','🦌','🐕','🐩','🦮','🐕‍🦺','🐈','🐈‍⬛','🪶'],
            food: ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🥦','🥬','🥒','🌶️','🫑','🌽','🥕','🫒','🥔','🍠','🥐','🥯','🍞','🥖','🥨','🧀','🥚','🍳','🥞','🧇','🥓','🥩','🍗','🍖','🦴','🍔','🍟','🍕','🫓','🥪','🥙','🌮','🌯','🥗','🥘','🫕','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥠','🥮','🍢','🍡','🍧','🍨','🍦','🥧','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🍯','🥛','🍼','☕','🍵','🧃','🥤','🧋','🍶','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🧉','🍾'],
            objects: ['💻','📱','☎️','📞','📟','📠','🔋','🔌','🖨️','⌨️','🖱️','💾','💿','📀','🧮','🧭','🔧','🔨','🔩','⚙️','🧰','🧲','🪜','🧪','🔬','🔭','📡','💉','🩹','🩺','🚪','🛏️','🛋️','🚿','🛁','🪒','🧴','🧹','🧺','🧻','🪣','🧼','🧽','🧯','🛒'],
            symbols: ['❤️','🧡','💛','💚','💙','💜','🤎','🖤','🤍','💔','❤️️‍🔥','❤️‍🩹','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉️','☸️','✡️','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️','🚷','🚯','🚳','🚱','🔞','📵','🚭','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸','🔱','⚜️','🟢','🟡','🟠','🔴','🟣','🟤','⚫','⚪','🟥','🟧','🟨','🟩','🟦','🟪','🟫','⬛','⬜']
        };

        let currentSelectedPhone = '';
        let currentIsSaved = false;
        let pollingInterval = null;
        let isFetchingMessages = false;
        let isSyncingContacts = false;
        let mediaRecorder = null;
        let audioChunks = [];
        let recordTimerInterval = null;
        let recordSeconds = 0;
        let activeCallStream = null;
        let activeReactionMsgId = null;
        let pendingPastedBlob = null;
        let activeFocusedInput = 'single';
        let bulkAttachedFiles = [];
        let savedContactsList = <?= json_encode($contacts) ?> || [];
        let currentFilter = 'all';
        let cameraStream = null;

        let isSelectMode = false;
        let selectedMessageIds = new Set();
        let currentLoadedMessages = [];
        let lastMessagesJSON = ''; // Anti-Blink Engine Signature

        // Client-side Memory Cache for instant chat loading
        let chatCache = {};

        let currentGalleryImages = [];
        let currentGalleryIndex = 0;

        let forwardSelectedContacts = new Set();
        let targetScrollMatchId = null;
        let isCurrentChatWubachatUser = false;
        let isCurrentChatFreeReplyWindow = false;
        let activeRowMenuPhone = '';
        let currentDrawerContactId = 0;
        let fetchedWalletRecords = [];
        let lastSyncSignature = ''; // Stable list signature to prevent jump/flicker

        // Dynamic QR amount updater & validation function
        function showToast(message, isError = false) {
            const toast = document.getElementById('app-toast');
            const toastMsg = document.getElementById('app-toast-msg');
            const toastIcon = document.getElementById('app-toast-icon');
            if (!toast) return;
            toastMsg.innerText = message;
            if (isError) {
                toast.className = 'fixed top-5 right-5 z-[999999] bg-red-600 text-white text-xs font-bold px-4 py-2.5 rounded-2xl shadow-2xl flex items-center space-x-2 transition-all duration-300 opacity-100 transform translate-y-0';
                toastIcon.className = 'fa-solid fa-triangle-exclamation text-sm';
            } else {
                toast.className = 'fixed top-5 right-5 z-[999999] bg-emerald-600 text-white text-xs font-bold px-4 py-2.5 rounded-2xl shadow-2xl flex items-center space-x-2 transition-all duration-300 opacity-100 transform translate-y-0';
                toastIcon.className = 'fa-solid fa-circle-check text-sm';
            }
            setTimeout(() => {
                toast.className = 'fixed top-5 right-5 z-[999999] bg-emerald-600 text-white text-xs font-bold px-4 py-2.5 rounded-2xl shadow-2xl flex items-center space-x-2 transition-all duration-300 opacity-0 pointer-events-none transform translate-y-[-10px]';
            }, 3000);
        }
        const baseAdminUpi = "<?= htmlspecialchars($admin_upi) ?>";
        const baseAdminPayee = "<?= htmlspecialchars($admin_payee) ?>";
        const loggedInUserPhone = "<?= htmlspecialchars($user_phone) ?>";

        function validateAndSyncQR(val) {
            let amt = parseFloat(val);
            const errorSpan = document.getElementById('recharge-error-msg');
            const qrImage = document.getElementById('upi-qr-image');

            if (isNaN(amt) || amt < 100) {
                errorSpan.classList.remove('hidden');
                amt = 100;
            } else {
                errorSpan.classList.add('hidden');
            }

            const upiString = `upi://pay?pa=${encodeURIComponent(baseAdminUpi)}&pn=${encodeURIComponent(baseAdminPayee)}&am=${amt.toFixed(2)}&cu=INR`;
            qrImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(upiString)}`;
        }

        function openModal() { document.getElementById('contact-modal').classList.remove('hidden'); document.getElementById('contact-modal').classList.add('flex'); }
        function closeModal() { document.getElementById('contact-modal').classList.add('hidden'); document.getElementById('contact-modal').classList.remove('flex'); }
        function openSettingsModal() { document.getElementById('settings-modal').classList.remove('hidden'); document.getElementById('settings-modal').classList.add('flex'); }
        function closeSettingsModal() { document.getElementById('settings-modal').classList.add('hidden'); document.getElementById('settings-modal').classList.remove('flex'); }
        function openMetaConnectModal() { document.getElementById('meta-connect-modal').classList.remove('hidden'); document.getElementById('meta-connect-modal').classList.add('flex'); }
        function closeMetaConnectModal() { document.getElementById('meta-connect-modal').classList.add('hidden'); document.getElementById('meta-connect-modal').classList.remove('flex'); }
        function openAutoReplyModal() { document.getElementById('autoreply-modal').classList.remove('hidden'); document.getElementById('autoreply-modal').classList.add('flex'); }
        function closeAutoReplyModal() { document.getElementById('autoreply-modal').classList.add('hidden'); document.getElementById('autoreply-modal').classList.remove('flex'); }
        function openRechargeModal() { document.getElementById('recharge-modal').classList.remove('hidden'); document.getElementById('recharge-modal').classList.add('flex'); }
        function closeRechargeModal() { document.getElementById('recharge-modal').classList.add('hidden'); document.getElementById('recharge-modal').classList.remove('flex'); }
        function openImportContactsModal() { document.getElementById('import-contacts-modal').classList.remove('hidden'); document.getElementById('import-contacts-modal').classList.add('flex'); }
        function closeImportContactsModal() { document.getElementById('import-contacts-modal').classList.add('hidden'); document.getElementById('import-contacts-modal').classList.remove('flex'); }
        function openImportBackupModal() { document.getElementById('import-backup-modal').classList.remove('hidden'); document.getElementById('import-backup-modal').classList.add('flex'); }
        function closeImportBackupModal() { document.getElementById('import-backup-modal').classList.add('hidden'); document.getElementById('import-backup-modal').classList.remove('flex'); }
        function openContactBookModal() { document.getElementById('contact-book-modal').classList.remove('hidden'); document.getElementById('contact-book-modal').classList.add('flex'); renderContactBookTable(); }
        function closeContactBookModal() { document.getElementById('contact-book-modal').classList.add('hidden'); document.getElementById('contact-book-modal').classList.remove('flex'); }
        function openLogoutModal() { document.getElementById('logout-confirmation-modal').classList.remove('hidden'); document.getElementById('logout-confirmation-modal').classList.add('flex'); }
        function closeLogoutModal() { document.getElementById('logout-confirmation-modal').classList.add('hidden'); document.getElementById('logout-confirmation-modal').classList.remove('flex'); }
        function openBillingAuditModal() { document.getElementById('billing-audit-modal').classList.remove('hidden'); document.getElementById('billing-audit-modal').classList.add('flex'); loadBillingAuditLogs(); }
        function closeBillingAuditModal() { document.getElementById('billing-audit-modal').classList.add('hidden'); document.getElementById('billing-audit-modal').classList.remove('flex'); }
        function openBlockedUsersModal() { document.getElementById('blocked-users-modal').classList.remove('hidden'); document.getElementById('blocked-users-modal').classList.add('flex'); loadBlockedUsersList(); }
        function closeBlockedUsersModal() { document.getElementById('blocked-users-modal').classList.add('hidden'); document.getElementById('blocked-users-modal').classList.remove('flex'); }

        function openTemplateSelectModal() {
            document.getElementById('template-select-modal').classList.remove('hidden');
            document.getElementById('template-select-modal').classList.add('flex');
        }
        function closeTemplateSelectModal() {
            document.getElementById('template-select-modal').classList.add('hidden');
            document.getElementById('template-select-modal').classList.remove('flex');
        }
        function selectTemplateAndOpen(templateName) {
            closeTemplateSelectModal();
            document.getElementById('modal-tpl-claim').value = '';
            document.getElementById('modal-tpl-vehicle').value = '';
            document.getElementById('modal-tpl-link').value = '';
            document.getElementById('survey-template-modal').classList.remove('hidden');
            document.getElementById('survey-template-modal').classList.add('flex');
            document.getElementById('modal-tpl-claim').focus();
        }

        function closeSurveyTemplateModal() {
            document.getElementById('survey-template-modal').classList.add('hidden');
            document.getElementById('survey-template-modal').classList.remove('flex');
        }

        function openPdfReceiptModal(txId) {
            const tx = fetchedWalletRecords.find(item => item.id == txId);
            if (!tx) return;

            document.getElementById('receipt-id-title').innerText = 'WUBA-REC-' + tx.id;
            document.getElementById('rcpt-receipt-id').innerText = 'WUBA-REC-' + (100000 + Number(tx.id));
            
            let formattedDate = tx.created_at || '';
            try {
                let dt = new Date(formattedDate.replace(' ', 'T') + 'Z');
                formattedDate = dt.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', hour12: false });
            } catch (e) {
                formattedDate = tx.created_at;
            }

            document.getElementById('rcpt-date').innerText = formattedDate;
            document.getElementById('rcpt-amount').innerText = '₹' + parseFloat(tx.amount || 0).toFixed(2);
            document.getElementById('rcpt-utr').innerText = tx.utr_number || 'N/A';
            
            const payerName = "<?= htmlspecialchars($display_name) ?>";
            let rawPhone = "<?= htmlspecialchars($user_phone) ?>".replace(/\D/g, '');
            if (rawPhone.startsWith('91') && rawPhone.length > 10) {
                rawPhone = rawPhone.substr(2);
            }
            const payerPhone = rawPhone ? ' (+91 ' + rawPhone + ')' : '';
            
            document.getElementById('rcpt-payer').innerText = payerName + payerPhone;

            document.getElementById('pdf-receipt-modal').classList.remove('hidden');
            document.getElementById('pdf-receipt-modal').classList.add('flex');
        }

        function closePdfReceiptModal() {
            document.getElementById('pdf-receipt-modal').classList.add('hidden');
            document.getElementById('pdf-receipt-modal').classList.remove('flex');
        }

        function downloadReceiptPDF() {
            const element = document.getElementById('receipt-printable-area');
            const opt = {
                margin:       10,
                filename:     'Wubachat_Payment_Receipt.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            html2pdf().from(element).set(opt).save();
        }

        function switchBillingTab(tab) {
            const msgView = document.getElementById('billing-messages-view');
            const walletView = document.getElementById('billing-wallet-view');
            const msgBtn = document.getElementById('billing-tab-msg-btn');
            const walletBtn = document.getElementById('billing-tab-wallet-btn');

            if (tab === 'messages') {
                msgView.classList.remove('hidden');
                walletView.classList.add('hidden');
                msgBtn.className = "px-4 py-2 bg-white text-[#009b67] rounded-t-xl border-t border-x border-gray-200 shadow-xs";
                walletBtn.className = "px-4 py-2 text-gray-600 rounded-t-xl hover:bg-gray-200 transition";
            } else {
                walletView.classList.remove('hidden');
                msgView.classList.add('hidden');
                walletBtn.className = "px-4 py-2 bg-white text-[#009b67] rounded-t-xl border-t border-x border-gray-200 shadow-xs";
                msgBtn.className = "px-4 py-2 text-gray-600 rounded-t-xl hover:bg-gray-200 transition";
            }
        }

        function openEditContactModal(id, currentName, currentPhone) {
            document.getElementById('edit-contact-id').value = id || 0;
            document.getElementById('edit-contact-name').value = currentName || '';
            
            let cleanP = String(currentPhone || '').replace(/\D/g, '');
            if (cleanP.startsWith('91') && cleanP.length === 12) cleanP = cleanP.substr(2);
            document.getElementById('edit-contact-phone').value = cleanP;

            document.getElementById('edit-contact-modal').classList.remove('hidden');
            document.getElementById('edit-contact-modal').classList.add('flex');
            document.getElementById('edit-contact-name').focus();
        }

        function closeEditContactModal() {
            document.getElementById('edit-contact-modal').classList.add('hidden');
            document.getElementById('edit-contact-modal').classList.remove('flex');
        }

        function openEditFromDrawer() {
            const currentName = document.getElementById('drawer-contact-name').innerText;
            openEditContactModal(currentDrawerContactId, currentName, currentSelectedPhone);
        }

        async function handleUpdateContact(e) {
            e.preventDefault();
            const id = document.getElementById('edit-contact-id').value;
            const name = document.getElementById('edit-contact-name').value.trim();
            let rawPhone = document.getElementById('edit-contact-phone').value.trim();
            
            if (!name || !rawPhone) {
                alert('Please enter both name and mobile number.');
                return;
            }

            let cleanPhone = rawPhone.replace(/\D/g, '');
            if (cleanPhone.length === 10) cleanPhone = '91' + cleanPhone;
            else if (cleanPhone.length === 11 && cleanPhone.startsWith('0')) cleanPhone = '91' + cleanPhone.substr(1);

            const btn = document.getElementById('update-contact-btn');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Saving...';

            const fd = new FormData();
            fd.append('action', 'edit_contact');
            fd.append('id', id);
            fd.append('name', name);
            fd.append('phone', cleanPhone);

            try {
                const res = await fetch('api/chat_actions.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status) {
                    closeEditContactModal();
                    
                    if (currentSelectedPhone) {
                        localStorage.setItem('wuba_active_phone', cleanPhone);
                        localStorage.setItem('wuba_active_name', name);
                        localStorage.setItem('wuba_active_saved', 'true');
                    }
                    location.reload();
                } else {
                    alert(data.message || 'Error updating contact.');
                }
            } catch (err) {
                alert('Network error while updating contact.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        async function openContactDrawer(phone) {
            if (!phone) return;
            const drawer = document.getElementById('contact-info-drawer');
            drawer.classList.remove('hidden');
            drawer.classList.add('flex');

            const activeNameTitle = document.getElementById('active-chat-name').innerText;
            document.getElementById('drawer-contact-name').innerText = activeNameTitle || ('+' + phone);
            document.getElementById('drawer-phone-display').innerText = '+' + phone;
            document.getElementById('drawer-about-display').innerText = 'Hey there! I am using WhatsApp.';

            const avatarEl = document.getElementById('drawer-avatar');
            avatarEl.innerHTML = (activeNameTitle || 'U').substr(0, 2).toUpperCase();

            try {
                const res = await fetch(`api/chat_actions.php?action=get_contact_info&phone=${encodeURIComponent(phone)}`);
                const d = await res.json();
                
                if (d.status) {
                    currentDrawerContactId = d.contact_id || 0;
                    
                    if (d.contact_name && !d.contact_name.includes('+')) {
                        document.getElementById('drawer-contact-name').innerText = d.contact_name;
                        avatarEl.innerHTML = d.contact_name.substr(0, 2).toUpperCase();
                    }
                    
                    document.getElementById('drawer-phone-display').innerText = '+' + (d.phone || phone);
                    
                    const resolvedBio = (d.bio && String(d.bio).trim() !== '') ? d.bio : 'Hey there! I am using WhatsApp.';
                    document.getElementById('drawer-about-display').innerText = resolvedBio;
                    
                    if (d.avatar) {
                        avatarEl.innerHTML = `<img src="${d.avatar}" class="w-full h-full object-cover">`;
                    }
                    
                    const blockBtnText = document.getElementById('drawer-block-text');
                    blockBtnText.innerText = d.is_blocked ? 'Unblock Contact' : 'Block Contact';

                    const blockedBanner = document.getElementById('chat-blocked-banner');
                    if (d.is_blocked) {
                        blockedBanner.classList.remove('hidden');
                        blockedBanner.classList.add('flex');
                    } else {
                        blockedBanner.classList.add('hidden');
                        blockedBanner.classList.remove('flex');
                    }

                    const mediaGrid = document.getElementById('drawer-media-grid');
                    const totalMedia = (d.media && Array.isArray(d.media)) ? d.media.length : 0;
                    document.getElementById('drawer-media-count').innerText = totalMedia;
                    
                    if (totalMedia > 0) {
                        mediaGrid.innerHTML = d.media.slice(0, 6).map(m => `
                            <a href="${m.url}" target="_blank" class="aspect-square rounded-xl overflow-hidden bg-black/10 border block">
                                <img src="${m.url}" class="w-full h-full object-cover">
                            </a>
                        `).join('');
                    } else {
                        mediaGrid.innerHTML = '<span class="text-[11px] text-gray-400 italic col-span-3">No media shared yet.</span>';
                    }
                }
            } catch (e) {
                document.getElementById('drawer-about-display').innerText = 'Hey there! I am using WhatsApp.';
            }
        }

        function closeContactDrawer() {
            const drawer = document.getElementById('contact-info-drawer');
            drawer.classList.add('hidden');
            drawer.classList.remove('flex');
        }

        function copyDrawerPhone() {
            const phoneTxt = document.getElementById('drawer-phone-display').innerText;
            navigator.clipboard.writeText(phoneTxt);
            alert('Phone number copied: ' + phoneTxt);
        }

        async function toggleDrawerBlockContact() {
            if (!currentSelectedPhone) return;
            const btnText = document.getElementById('drawer-block-text').innerText;
            const action = (btnText.includes('Unblock')) ? 'unblock_contact' : 'block_contact';
            const fd = new FormData();
            fd.append('action', action);
            fd.append('phone', currentSelectedPhone);
            const res = await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            openContactDrawer(currentSelectedPhone);
        }

        function toggleContactRowMenu(e, phone) {
            e.stopPropagation();
            activeRowMenuPhone = phone;
            const menu = document.getElementById('contact-row-menu');
            
            const matchContact = savedContactsList.find(c => c.phone_number === phone || c.phone_number.endsWith(phone.slice(-10)));
            if (matchContact) {
                document.getElementById('row-archive-btn').innerHTML = matchContact.is_archived 
                    ? '<i class="fa-solid fa-box-open text-gray-400"></i><span>Unarchive chat</span>' 
                    : '<i class="fa-solid fa-box-archive text-gray-400"></i><span>Archive chat</span>';
                document.getElementById('row-important-btn').innerHTML = matchContact.is_important 
                    ? '<i class="fa-solid fa-star-half-stroke text-amber-400"></i><span>Remove Important</span>' 
                    : '<i class="fa-solid fa-star text-amber-400"></i><span>Mark Important</span>';
            }

            const menuHeight = 190; 
            let topPosition = e.clientY + 10;

            if (e.clientY + menuHeight > window.innerHeight) {
                topPosition = Math.max(10, e.clientY - menuHeight);
            }

            menu.style.top = topPosition + 'px';
            menu.style.left = (e.clientX - 160) + 'px';
            menu.classList.toggle('hidden');
        }

        async function triggerRowAction(actionType) {
            document.getElementById('contact-row-menu').classList.add('hidden');
            if (!activeRowMenuPhone) return;

            const targetPhone = activeRowMenuPhone;

            if (actionType === 'delete_conversation') {
                if (!confirm(`Delete entire conversation with +${targetPhone}?`)) return;
                const fd = new FormData();
                fd.append('action', 'delete_conversation');
                fd.append('phone', targetPhone);
                await fetch('api/chat_actions.php', { method: 'POST', body: fd });
                
                savedContactsList = savedContactsList.filter(c => c.phone_number !== targetPhone && !c.phone_number.endsWith(targetPhone.slice(-10)));
                delete chatCache[targetPhone];
                renderDefaultContactsList();
                if (currentSelectedPhone === targetPhone || currentSelectedPhone.endsWith(targetPhone.slice(-10))) {
                    currentSelectedPhone = '';
                    document.getElementById('messages-stream').innerHTML = `<div class="flex justify-center my-4"><span class="bg-white/80 text-gray-500 text-[11px] px-3 py-1 rounded-full shadow-sm">Select a contact to start messaging</span></div>`;
                }
                return;
            }

            const fd = new FormData();
            const contactObj = savedContactsList.find(c => c.phone_number === targetPhone || c.phone_number.endsWith(targetPhone.slice(-10)));

            if (actionType === 'unread') {
                fd.append('action', 'mark_unread');
                if (contactObj) contactObj.unread_count = 1;
            } else if (actionType === 'archive') {
                const isCurrentlyArchived = contactObj ? contactObj.is_archived : false;
                fd.append('action', isCurrentlyArchived ? 'unarchive_chat' : 'archive_chat');
                if (contactObj) contactObj.is_archived = !isCurrentlyArchived;
            } else if (actionType === 'important') {
                fd.append('action', 'toggle_important_chat');
                if (contactObj) contactObj.is_important = !contactObj.is_important;
            } else if (actionType === 'block') {
                fd.append('action', 'block_contact');
            }
            fd.append('phone', targetPhone);

            await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            renderDefaultContactsList();
        }

        function previewSelectedAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('settings-avatar-preview').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function handleSaveProfileUpdate(e) {
            e.preventDefault();
            const name = document.getElementById('settings-profile-name').value.trim();
            const bio  = document.getElementById('settings-profile-bio').value.trim();
            const fileInput = document.getElementById('settings-profile-file');
            const btn = document.getElementById('profile-update-btn');

            btn.disabled = true;
            btn.innerText = 'Saving...';

            let avatarUrl = '';
            if (fileInput.files && fileInput.files[0]) {
                const uploadFd = new FormData();
                uploadFd.append('file', fileInput.files[0]);
                uploadFd.append('recipient', 'profile');
                uploadFd.append('media_type', 'image');
                try {
                    const upRes = await fetch('api/upload_media.php', { method: 'POST', body: uploadFd });
                    const upData = await upRes.json();
                    if (upData.status) {
                        avatarUrl = upData.media_url;
                    }
                } catch(e) {}
            }

            const fd = new FormData();
            fd.append('action', 'update_profile');
            fd.append('name', name);
            fd.append('bio', bio);
            if (avatarUrl) fd.append('avatar_url', avatarUrl);

            const res = await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message || 'Profile updated!');
            location.reload();
        }

        async function handleRemoveProfilePic() {
            if (!confirm('Remove profile photo?')) return;
            const fd = new FormData();
            fd.append('action', 'remove_profile_pic');
            await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            location.reload();
        }

        async function loadBlockedUsersList() {
            const container = document.getElementById('blocked-users-list-container');
            container.innerHTML = `<p class="text-center py-4 text-xs text-gray-400">Loading blocked list...</p>`;
            try {
                const res = await fetch('api/chat_actions.php?action=get_blocked_list');
                const d = await res.json();
                if (!d.records || d.records.length === 0) {
                    container.innerHTML = `<p class="text-center py-6 text-xs text-gray-400">No blocked contacts.</p>`;
                    return;
                }
                container.innerHTML = d.records.map(b => `
                    <div class="flex items-center justify-between py-2.5">
                        <div>
                            <span class="text-xs font-bold text-gray-800">+${b.phone_number}</span>
                            <span class="text-[10px] text-gray-400 block">${b.created_at}</span>
                        </div>
                        <button onclick="unblockFromModal('${b.phone_number}')" class="px-3 py-1 bg-emerald-50 hover:bg-emerald-100 text-[#00a884] font-bold rounded-xl text-xs transition">Unblock</button>
                    </div>
                `).join('');
            } catch(e) {
                container.innerHTML = `<p class="text-center py-4 text-xs text-red-500">Error loading list.</p>`;
            }
        }

        async function unblockFromModal(phone) {
            const fd = new FormData();
            fd.append('action', 'unblock_contact');
            fd.append('phone', phone);
            await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            loadBlockedUsersList();
        }

        async function loadBillingAuditLogs() {
            const from = document.getElementById('billing-from-date').value;
            const to   = document.getElementById('billing-to-date').value;
            const tbody = document.getElementById('billing-table-body');
            const walletTbody = document.getElementById('billing-wallet-table-body');

            tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-gray-400">Loading audit logs...</td></tr>`;
            walletTbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-gray-400">Loading payment receipts...</td></tr>`;

            try {
                const res = await fetch(`api/chat_actions.php?action=fetch_billing_logs&from_date=${from}&to_date=${to}`);
                const d = await res.json();
                
                if (!d.records || d.records.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-gray-400">No transactions recorded in this date range.</td></tr>`;
                } else {
                    tbody.innerHTML = d.records.map(r => `
                        <tr class="hover:bg-gray-50 border-b">
                            <td class="py-2 px-3 font-mono text-[11px] text-gray-500">${r.created_at}</td>
                            <td class="py-2 px-3 font-mono font-bold">+${r.recipient_phone}</td>
                            <td class="py-2 px-3 uppercase text-[10px] font-bold text-gray-600">${r.message_type}</td>
                            <td class="py-2 px-3 uppercase text-[10px] font-bold ${r.sent_via === 'wubachat' ? 'text-[#00a884]' : 'text-blue-600'}">${r.sent_via}</td>
                            <td class="py-2 px-3 text-right font-extrabold ${parseFloat(r.cost) > 0 ? 'text-amber-600' : 'text-emerald-600'}">₹${parseFloat(r.cost).toFixed(2)}</td>
                        </tr>
                    `).join('');
                }

                fetchedWalletRecords = d.wallet_records || [];
                if (fetchedWalletRecords.length === 0) {
                    walletTbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-gray-400">No wallet payment receipts found in this range.</td></tr>`;
                } else {
                    walletTbody.innerHTML = fetchedWalletRecords.map(w => `
                        <tr class="hover:bg-gray-50 border-b cursor-pointer" onclick="openPdfReceiptModal(${w.id})">
                            <td class="py-2.5 px-3 font-mono text-[11px] text-gray-500">${w.created_at}</td>
                            <td class="py-2.5 px-3 font-mono font-bold">UTR: ${w.utr_number || 'TRN_' + w.id}</td>
                            <td class="py-2.5 px-3">Wubachat (Business Entity)</td>
                            <td class="py-2.5 px-3 text-right font-black text-[#009b67]">₹${parseFloat(w.amount).toFixed(2)}</td>
                            <td class="py-2.5 px-3 text-center">
                                <button onclick="event.stopPropagation(); openPdfReceiptModal(${w.id})" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-[#00a884] font-bold rounded-xl text-[10px] shadow-xs transition">
                                    <i class="fa-solid fa-file-pdf mr-1"></i> View Receipt
                                </button>
                            </td>
                        </tr>
                    `).join('');
                }
            } catch(e) {
                tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-red-500">Error loading billing records.</td></tr>`;
            }
        }

        window.addEventListener('click', function(e) {
            const optMenu = document.getElementById('options-dropdown');
            if (optMenu && !optMenu.classList.contains('hidden')) {
                if (!e.target.closest('.dropdown-container')) optMenu.classList.add('hidden');
            }

            const rowMenu = document.getElementById('contact-row-menu');
            if (rowMenu && !rowMenu.classList.contains('hidden')) rowMenu.classList.add('hidden');

            const attachMenu = document.getElementById('attachment-menu');
            if (attachMenu && !attachMenu.classList.contains('hidden')) {
                if (!e.target.closest('#attachment-menu') && !e.target.closest('[title="Attach Media"]')) attachMenu.classList.add('hidden');
            }

            const emojiPanel = document.getElementById('emoji-panel');
            if (emojiPanel && !emojiPanel.classList.contains('hidden')) {
                if (!e.target.closest('#emoji-panel') && !e.target.closest('[title="Insert Emoji"]')) emojiPanel.classList.add('hidden');
            }

            const transPopover = document.getElementById('translation-popover');
            if (transPopover && !transPopover.classList.contains('hidden')) {
                if (!e.target.closest('#translation-popover') && !e.target.closest('.translate-trigger-btn') && !e.target.closest('#chat-translate-btn')) {
                    closeTranslationPopover();
                }
            }

            const reactionBar = document.getElementById('quick-reaction-bar');
            if (reactionBar && !reactionBar.classList.contains('hidden')) {
                if (!e.target.closest('#quick-reaction-bar') && !e.target.closest('[title="React"]')) reactionBar.classList.add('hidden');
            }

            document.querySelectorAll('.modal-wrapper').forEach(modal => {
                if (modal && !modal.classList.contains('hidden') && e.target === modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            });
        });

        let searchDebounceTimer = null;

        async function handleUniversalSearch() {
            const query = (document.getElementById('contact-search').value || '').trim().toLowerCase();
            const clearBtn = document.getElementById('clear-search-btn');
            const container = document.getElementById('contacts-list-container');
            
            if (query.length > 0) clearBtn.classList.remove('hidden');
            else clearBtn.classList.add('hidden');

            if (query.length === 0) {
                renderDefaultContactsList();
                return;
            }

            // 1. Instant Contacts Name & Phone Match (0ms)
            const directMatches = savedContactsList.filter(c => 
                (c.contact_name && c.contact_name.toLowerCase().includes(query)) || 
                (c.phone_number && c.phone_number.includes(query))
            );

            let html = '';
            if (directMatches.length > 0) {
                html += `<div class="px-3 py-1.5 bg-gray-100/70 text-[10px] uppercase font-extrabold text-gray-500 tracking-wider">Chats & Contacts</div>`;
                directMatches.forEach(c => {
                    const highlightedTitle = highlightSearchTerm(c.contact_name, query);
                    const highlightedLastMsg = highlightSearchTerm(c.last_msg, query);
                    html += `
                        <div onclick="selectChat('${c.phone_number}', '${escapeHtml(addslashes(c.contact_name))}', ${c.is_saved ? 'true' : 'false'})" class="contact-item p-3 hover:bg-[#f5f6f6] cursor-pointer flex items-center space-x-3 transition border-b border-gray-50/80">
                            <div class="relative w-11 h-11 bg-teal-100 text-teal-800 font-black rounded-full flex items-center justify-center text-sm uppercase shrink-0">
                                ${c.contact_name.substr(0, 2).toUpperCase()}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between items-baseline mb-0.5">
                                    <h4 class="text-xs font-bold text-gray-900 truncate contact-title">${highlightedTitle}</h4>
                                    <span class="text-[10px] text-gray-400 shrink-0">${c.last_time || ''}</span>
                                </div>
                                <p class="text-[11px] text-gray-500 truncate max-w-[170px] contact-last-msg">${highlightedLastMsg}</p>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            }

            // 2. Chat Ke Andar Ka Word / Sentence Dhoondhna (Fast Server Query)
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`api/search_messages.php?q=${encodeURIComponent(query)}`);
                    const data = await res.json();

                    if (data.status && data.results && data.results.length > 0) {
                        html += `<div class="px-3 py-1.5 bg-emerald-50 text-[10px] uppercase font-extrabold text-[#008069] tracking-wider mt-1 border-t border-emerald-100">Messages Found (${data.results.length})</div>`;
                        data.results.forEach(m => {
                            const snippet = highlightSearchTerm(m.message_text, query);
                            const contactName = m.contact_name || ('+' + m.recipient_phone);
                            const time = m.created_at ? formatTimeISTJS(m.created_at) : '';

                            html += `
                                <div onclick="selectChatAndJump('${m.recipient_phone}', '${escapeHtml(addslashes(contactName))}', ${m.msg_id})" class="contact-item p-3 hover:bg-emerald-50/60 cursor-pointer flex items-center space-x-3 transition border-b border-gray-50/80 bg-white">
                                    <div class="w-10 h-10 rounded-full bg-emerald-100 text-[#00a884] font-bold flex items-center justify-center text-xs shrink-0">
                                        <i class="fa-solid fa-message"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between items-baseline mb-0.5">
                                            <h4 class="text-xs font-bold text-gray-900 truncate">${escapeHtml(contactName)}</h4>
                                            <span class="text-[9px] text-gray-400 shrink-0">${time}</span>
                                        </div>
                                        <p class="text-[11px] text-gray-600 truncate leading-tight">${snippet}</p>
                                    </div>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                    } else if (directMatches.length === 0) {
                        container.innerHTML = `<div class="p-8 text-center text-gray-400 text-xs font-medium">No results found for "${escapeHtml(query)}"</div>`;
                    }
                } catch(e) {}
            }, 300);
        }

        function highlightSearchTerm(text, query) {
            if (!text || !query) return escapeHtml(text || '');
            const safeText = escapeHtml(text);
            const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
            return safeText.replace(regex, '<span class="highlight-match">$1</span>');
        }

        function clearUniversalSearch() {
            document.getElementById('contact-search').value = '';
            document.getElementById('clear-search-btn').classList.add('hidden');
            renderDefaultContactsList();
        }
        
        function getLocalMaxId(phone) {
            if (!phone) return 0;
            const clean = String(phone).replace(/\D/g, '');
            const k10 = clean.length >= 10 ? clean.slice(-10) : clean;
            const raw = localStorage.getItem('wuba_chat_' + k10) || localStorage.getItem('wuba_chat_' + clean);
            if (!raw) return 0;
            try {
                const arr = JSON.parse(raw);
                if (!Array.isArray(arr) || arr.length === 0) return 0;
                let max = 0;
                for (let i = 0; i < arr.length; i++) {
                    const nid = Number(arr[i].id);
                    if (!isNaN(nid) && nid > max) max = nid;
                }
                return max;
            } catch(e) {
                return 0;
            }
        }

        function isChatSynced(contact) {
            const serverMax = Number(contact.server_max_id || contact.max_id || 0);
            if (serverMax === 0) return true;
            const localMax = getLocalMaxId(contact.phone_number);
            return localMax >= serverMax;
        }

        function renderDefaultContactsList() {
            const container = document.getElementById('contacts-list-container');
            const currentScrollTop = container.scrollTop;
            
            let listToRender = [...savedContactsList];

            listToRender.sort((a, b) => {
                let idA = Number(a.max_id || a.id || 0);
                let idB = Number(b.max_id || b.id || 0);
                if (idA !== idB) {
                    return idB - idA;
                }
                let tA = a.raw_last_time ? new Date(a.raw_last_time).getTime() : 0;
                let tB = b.raw_last_time ? new Date(b.raw_last_time).getTime() : 0;
                return tB - tA;
            });

            if (currentFilter === 'unread') {
                listToRender = listToRender.filter(c => Number(c.unread_count) > 0);
            } else if (currentFilter === 'archive') {
                listToRender = listToRender.filter(c => !!c.is_archived);
            } else if (currentFilter === 'important') {
                listToRender = listToRender.filter(c => !!c.is_important);
            } else if (currentFilter === 'favourites') {
                listToRender = listToRender.filter(c => !!c.is_saved);
            } else {
                listToRender = listToRender.filter(c => !c.is_archived);
            }

            if (listToRender.length === 0) {
                container.innerHTML = `<div id="no-contacts-msg" class="p-8 text-center text-gray-400"><p class="text-xs font-medium">No conversations found in this tab.</p></div>`;
                return;
            }

            container.innerHTML = listToRender.map(c => {
                const unreadNum = Number(c.unread_count) || 0;
                return `
                <div onclick="selectChat('${c.phone_number}', '${escapeHtml(addslashes(c.contact_name))}', ${c.is_saved ? 'true' : 'false'})" class="contact-item p-3 hover:bg-[#f5f6f6] cursor-pointer flex items-center justify-between transition border-b border-gray-50/80 group">
                    <div class="flex items-center space-x-3 min-w-0 flex-1">
                        <div class="relative w-11 h-11 bg-teal-100 text-teal-800 font-black rounded-full flex items-center justify-center text-sm uppercase shrink-0">
                            ${c.contact_name.substr(0, 2).toUpperCase()}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-baseline mb-0.5">
                                <div class="flex items-center space-x-1.5 truncate">
    <h4 class="text-xs font-bold text-gray-900 truncate contact-title">${escapeHtml(c.contact_name)}</h4>${isChatSynced(c) ? `
        <span class="text-[8px] font-black px-1.5 py-0.2 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200/80 flex items-center shrink-0">
            <i class="fa-solid fa-check-double text-[7px] mr-1 text-emerald-500"></i> Synced
        </span>
    ` : `
        <span class="text-[8px] font-black px-1.5 py-0.2 rounded-md bg-amber-50 text-amber-700 border border-amber-200/80 flex items-center shrink-0 animate-pulse">
            <i class="fa-solid fa-arrows-rotate text-[7px] mr-1 text-amber-500"></i> Not Synced
        </span>
    `}
</div>
                                <span class="text-[10px] ${unreadNum > 0 ? 'text-[#25d366] font-bold' : 'text-gray-400'} shrink-0">${c.last_time || ''}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <p class="text-[11px] ${unreadNum > 0 ? 'text-gray-900 font-semibold' : 'text-gray-500'} truncate max-w-[170px] contact-last-msg">${escapeHtml(c.last_msg)}</p>
                                ${unreadNum > 0 ? `<span class="bg-[#25d366] text-white text-[10px] font-extrabold rounded-full min-w-[20px] h-5 px-1.5 flex items-center justify-center shadow-sm shrink-0">${unreadNum}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="relative dropdown-container ml-2">
                        <button type="button" onclick="toggleContactRowMenu(event, '${c.phone_number}')" class="opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-gray-700 transition">
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </button>
                    </div>
                </div>
                `;
            }).join('');

            container.scrollTop = currentScrollTop;
        }

        function selectChatAndJump(phone, name, msgId) {
            targetScrollMatchId = msgId;
            selectChat(phone, name, false);
        }
        function jumpToQuotedMessage(msgId) {
            if (!msgId) return;
            const targetEl = document.getElementById(`msg-bubble-${msgId}`);
            if (targetEl) {
                targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                targetEl.classList.remove('message-jump-focus');
                void targetEl.offsetWidth; // Reflow trigger taaki animation har baar chale
                targetEl.classList.add('message-jump-focus');
                setTimeout(() => {
                    targetEl.classList.remove('message-jump-focus');
                }, 2200);
            } else {
                showToast('Original message is further up in history', true);
            }
        }
        function filterContactsLocal() {
            const query = (document.getElementById('contact-search').value || '').toLowerCase();
            const items = document.querySelectorAll('.contact-item');
            items.forEach(el => {
                const text = el.innerText.toLowerCase();
                el.style.display = text.includes(query) ? 'flex' : 'none';
            });
        }

        function setContactFilter(type) {
            currentFilter = type;
            ['all', 'unread', 'favourites', 'important', 'archive'].forEach(t => {
                const btn = document.getElementById(`filter-btn-${t}`);
                if (btn) {
                    btn.className = (t === type) ? "px-3 py-1 rounded-full bg-[#e7fce3] text-[#008069] font-bold shrink-0" : "px-3 py-1 rounded-full bg-[#f0f2f5] text-gray-600 hover:bg-gray-200 shrink-0";
                }
            });
            renderDefaultContactsList();
        }

        function renderContactBookTable() {
            const query = (document.getElementById('contact-book-search').value || '').toLowerCase();
            const tbody = document.getElementById('contact-book-table-body');
            const filtered = savedContactsList.filter(c => (c.contact_name && c.contact_name.toLowerCase().includes(query)) || (c.phone_number && c.phone_number.includes(query)));
            
            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="3" class="py-6 text-center text-gray-400">No saved contacts found.</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(c => `
                <tr class="hover:bg-gray-50 border-b">
                    <td class="py-2.5 px-3 font-bold text-gray-800">${escapeHtml(c.contact_name)}</td>
                    <td class="py-2.5 px-3 font-mono text-gray-600">+${c.phone_number}</td>
                    <td class="py-2.5 px-3 text-right space-x-1.5">
                        <button onclick="closeContactBookModal(); selectChat('${c.phone_number}', '${escapeHtml(addslashes(c.contact_name))}', true);" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-[#00a884] font-bold rounded-lg text-xs transition" title="Message Contact">
                            <i class="fa-solid fa-comment mr-1"></i> Message
                        </button>
                        <button onclick="openEditContactModal(${c.id}, '${escapeHtml(addslashes(c.contact_name))}', '${c.phone_number}')" class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-600 font-bold rounded-lg text-xs transition" title="Edit Contact Name/Number">
                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                        </button>
                        <button onclick="deleteContactEntry(${c.id})" class="text-red-500 hover:text-red-700 font-bold px-2 py-1">Delete</button>
                    </td>
                </tr>
            `).join('');
        }

        async function deleteContactEntry(id) {
            if (!confirm('Delete contact?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_contact');
            fd.append('id', id);
            await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            location.reload();
        }

        function escapeHtml(text) {
            return String(text || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        }

        function addslashes(str) {
            return (str + '').replace(/[\\"']/g, '\\$&').replace(/\u0000/g, '\\0');
        }

        function formatTimeISTJS(isoString) {
            if (!isoString) return '';
            try {
                let cleanStr = isoString.replace(' ', 'T');
                if (!cleanStr.endsWith('Z') && !cleanStr.includes('+')) cleanStr += 'Z';
                const d = new Date(cleanStr);
                return d.toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit', hour12: false });
            } catch (ex) {
                return isoString.substr(11, 5);
            }
        }

        function getMessageDateGroupLabel(isoString) {
            if (!isoString) return '';
            try {
                let cleanStr = isoString.replace(' ', 'T');
                if (!cleanStr.endsWith('Z') && !cleanStr.includes('+')) cleanStr += 'Z';
                const d = new Date(cleanStr);
                const today = new Date();
                const yesterday = new Date();
                yesterday.setDate(today.getDate() - 1);

                const dDate = d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata' });
                const todayDate = today.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata' });
                const yestDate = yesterday.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata' });

                if (dDate === todayDate) return 'Today';
                if (dDate === yestDate) return 'Yesterday';

                return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', year: 'numeric' });
            } catch (e) {
                return isoString.split(' ')[0] || '';
            }
        }

        function formatDateTimeISTJS(isoString) {
            if (!isoString) return '';
            try {
                let cleanStr = isoString.replace(' ', 'T');
                if (!cleanStr.endsWith('Z') && !cleanStr.includes('+')) cleanStr += 'Z';
                const d = new Date(cleanStr);
                const datePart = d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', year: 'numeric' });
                const timePart = d.toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit', hour12: false });
                return `${datePart}, ${timePart}`;
            } catch (e) {
                return isoString;
            }
        }

        /* -------------------------------------------------------------
           OPTIMIZED INSTANT CHAT SELECTION WITH LOCAL STATE CACHING
           ------------------------------------------------------------- */
        let activeChatController = null;

        function selectChat(phone, name, isSaved = false) {
            // Abort previous chat fetch if user clicked another contact quickly
            if (activeChatController) {
                activeChatController.abort();
            }
            activeChatController = new AbortController();

            currentSelectedPhone = phone.replace(/\D/g, '');
            currentIsSaved = isSaved;
            
            localStorage.setItem('wuba_active_phone', currentSelectedPhone);
            localStorage.setItem('wuba_active_name', name || '+' + currentSelectedPhone);
            localStorage.setItem('wuba_active_saved', isSaved);

            document.getElementById('target-phone-input').value = currentSelectedPhone;
            document.getElementById('active-chat-name').innerText = name || '+' + currentSelectedPhone;
            
            const matchedObj = savedContactsList.find(x => x.phone_number === currentSelectedPhone || x.phone_number.endsWith(currentSelectedPhone.slice(-10)));
            if (matchedObj) {
                isCurrentChatFreeReplyWindow = !!matchedObj.is_free_reply_active;
                if (matchedObj.unread_count > 0) {
                    matchedObj.unread_count = 0;
                    renderDefaultContactsList();
                    markMessagesRead(currentSelectedPhone);
                }
            }

            const addBtn = document.getElementById('header-add-contact-btn');
            if (!isSaved && (!name || name.startsWith('+'))) {
                addBtn.classList.remove('hidden');
            } else {
                addBtn.classList.add('hidden');
            }

            checkTargetUserPresence(currentSelectedPhone);

            // INSTANT CACHE CHECK (0ms UI): Check both full phone and 10-digit key
            const last10 = currentSelectedPhone.slice(-10);
            const cachedMsgs = localStorage.getItem('wuba_chat_' + last10) || localStorage.getItem('wuba_chat_' + currentSelectedPhone);
            
            if (cachedMsgs) {
                try {
                    currentLoadedMessages = JSON.parse(cachedMsgs);
                    renderMessagesStream(currentLoadedMessages, true);
                } catch(e) {}
                loadMessages(currentSelectedPhone, false); // Silent background sync
            } else {
                // Agar number naya hai aur koi message nahi hai, toh bematlab ka spinner mat dikhao
                const isBrandNewChat = !matchedObj || matchedObj.last_msg === 'No messages yet' || matchedObj.last_msg === 'Tap to open chat';
                if (isBrandNewChat) {
                    renderMessagesStream([], true);
                    loadMessages(currentSelectedPhone, false);
                } else {
                    document.getElementById('messages-stream').innerHTML = `<div class="flex justify-center my-4"><span class="bg-white/80 text-gray-500 text-[11px] px-3 py-1 rounded-full shadow-sm"><i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Loading chat...</span></div>`;
                    lastMessagesJSON = '';
                    loadMessages(currentSelectedPhone, true);
                }
            }
            
            if (pollingInterval) clearInterval(pollingInterval);
            pollingInterval = setInterval(() => { 
                if (currentSelectedPhone && !isSelectMode) loadMessages(currentSelectedPhone, false); 
            }, 5000); // 5 seconds polling to save server CPU
        }

        async function markMessagesRead(phone) {
            try {
                const fd = new FormData();
                fd.append('action', 'mark_read');
                fd.append('phone', phone);
                await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            } catch(e) {}
        }

        async function checkTargetUserPresence(phone) {
            const badge = document.getElementById('wubachat-presence-badge');
            const inviteBtn = document.getElementById('header-invite-btn');
            const wubachatBtn = document.getElementById('btn-send-wubachat');
            badge.classList.add('hidden');
            inviteBtn.classList.add('hidden');

            try {
                const res = await fetch(`api/check_user.php?phone=${encodeURIComponent(phone)}`);
                const data = await res.json();
                badge.classList.remove('hidden');
                if (data.is_wubachat_user) {
                    isCurrentChatWubachatUser = true;
                    badge.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-emerald-100 text-emerald-800 flex items-center';
                    badge.innerHTML = '<i class="fa-solid fa-bolt text-amber-500 mr-1 text-[9px]"></i> On Wubachat (Free)';
                    inviteBtn.classList.add('hidden');
                    if (wubachatBtn) {
                        wubachatBtn.disabled = false;
                        wubachatBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                        wubachatBtn.title = "Send via Wubachat Free";
                    }
                } else {
                    isCurrentChatWubachatUser = false;
                    badge.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-gray-200 text-gray-700 flex items-center';
                    badge.innerHTML = '<i class="fa-brands fa-whatsapp mr-1 text-[9px]"></i> WhatsApp Only';
                    inviteBtn.classList.remove('hidden');
                    if (wubachatBtn) {
                        wubachatBtn.disabled = true;
                        wubachatBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        wubachatBtn.title = "Recipient not on Wubachat. Invite them or send via WhatsApp.";
                    }
                }
            } catch(e) {
                badge.classList.add('hidden');
            }
        }

        function inviteActiveContact() {
            if (!currentSelectedPhone) return;
            const inviteText = encodeURIComponent("Hey! Join me on Wubachat for fast, secure and free enterprise messaging: https://wubachat.com");
            window.open(`https://wa.me/${currentSelectedPhone}?text=${inviteText}`, '_blank');
        }

        function handleWhatsAppSendDecision() {
            const recipient = document.getElementById('target-phone-input').value.trim();
            const message = document.getElementById('target-message-input').value.trim();
            
            if (!recipient) {
                alert('Please select a chat first.');
                return;
            }
            if (!message && !pendingPastedBlob && !pendingMediaFile) {
                alert('Please type a message or attach a file to send.');
                return;
            }

            if (isCurrentChatFreeReplyWindow || pendingMediaFile || pendingPastedBlob) {
                dispatchDirectSend('whatsapp');
            } else {
                openTemplateSelectModal();
            }
        }

        async function handleSurveyModalSubmit(e) {
            e.preventDefault();
            const recipient = document.getElementById('target-phone-input').value.trim();
            const claimNo    = document.getElementById('modal-tpl-claim').value.trim();
            const vehicleNo  = document.getElementById('modal-tpl-vehicle').value.trim();
            const link       = document.getElementById('modal-tpl-link').value.trim();

            if (!claimNo || !vehicleNo || !link) {
                alert('Please fill out all 3 required template variables.');
                return;
            }

            // 1. POPUP TURANT 0ms MEIN CLOSE KARO
            closeSurveyTemplateModal();
            document.getElementById('target-message-input').value = '';
            cancelReplyMode();
            showToast('Template message sent!');

            // 2. SCREEN PAR TURANT MSG BUBBLE RENDER KARO
            const optimisticId = 'temp_' + Date.now();
            const templateText = `Paramount Inspection Alert\nClaim: ${claimNo}\nVehicle: ${vehicleNo}\nLink: ${link}`;
            
            const tempMsgObj = {
                id: optimisticId,
                recipient_phone: recipient,
                direction: 'outbound',
                message_text: templateText,
                sent_via: 'whatsapp',
                status: 'sent',
                created_at: new Date().toISOString()
            };

            currentLoadedMessages.push(tempMsgObj);
            renderMessagesStream(currentLoadedMessages, true);

            // 3. LOCAL STORAGE MEIN AUTO-SAVE KARO
            const last10Key = recipient.slice(-10);
            try {
                let existingLocal = [];
                const rawC = localStorage.getItem('wuba_chat_' + last10Key) || localStorage.getItem('wuba_chat_' + recipient);
                if (rawC) existingLocal = JSON.parse(rawC) || [];
                existingLocal.push(tempMsgObj);
                localStorage.setItem('wuba_chat_' + last10Key, JSON.stringify(existingLocal));
                localStorage.setItem('wuba_chat_' + recipient, JSON.stringify(existingLocal));
            } catch(e) {}

            // 4. BACKGROUND MEIN SILENT DISPATCH (User ka page freeze nahi hoga)
            const fd = new FormData();
            fd.append('recipient', recipient);
            fd.append('sent_via', 'whatsapp');
            fd.append('mode', 'template');
            fd.append('template_name', 'survey_alert');
            fd.append('claim_number', claimNo);
            fd.append('vehicle_number', vehicleNo);
            fd.append('inspection_link', link);

            fetch('api/send_message.php', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status) {
                        if (data.new_balance !== undefined) {
                            document.getElementById('top-wallet-display').innerText = '₹' + parseFloat(data.new_balance).toFixed(2);
                        }
                    } else {
                        const errLower = (data.message || '').toLowerCase();
                        if (errLower.includes('balance') || errLower.includes('insufficient') || errLower.includes('credit')) {
                            showToast('Low Balance! Please recharge to send message.', true);
                            openRechargeModal();
                            loadMessages(recipient, false);
                        } else {
                            alert(data.message || 'Error sending message.');
                        }
                    }
                })
                .catch(() => {});
        }

        async function dispatchDirectSend(channel = 'wubachat') {
            const recipient = document.getElementById('target-phone-input').value.trim();
            const msgInput = document.getElementById('target-message-input');
            const message = msgInput.value.trim();
            const replyWamid = document.getElementById('reply-wamid-input').value.trim();

            if (!recipient) {
                alert('Please select a chat first.');
                return;
            }

            if (pendingMediaFile) {
                const fd = new FormData();
                fd.append('recipient', recipient);
                fd.append('media_type', pendingMediaType);
                fd.append('file', pendingMediaFile);
                fd.append('sent_via', channel);
                if (message) fd.append('message', message);
                if (replyWamid) fd.append('reply_to_wamid', replyWamid);

                try {
                    const res = await fetch('api/upload_media.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.status) {
                        cancelPendingMedia();
                        msgInput.value = '';
                        cancelReplyMode();
                        lastMessagesJSON = '';
                        loadMessages(recipient, true);
                        if (data.new_balance !== undefined) {
                            document.getElementById('top-wallet-display').innerText = '₹' + parseFloat(data.new_balance).toFixed(2);
                        }
                    } else {
                        alert(data.message || 'Error sending media file.');
                    }
                } catch(e) {
                    alert('Network error while sending media.');
                }
                return;
            }

            if (pendingPastedBlob) {
                const fd = new FormData();
                fd.append('recipient', recipient);
                fd.append('media_type', 'image');
                fd.append('file', pendingPastedBlob, 'screenshot.png');
                fd.append('sent_via', channel);
                if (replyWamid) fd.append('reply_to_wamid', replyWamid);
                removePastedImage();
                cancelReplyMode();
                await fetch('api/upload_media.php', { method: 'POST', body: fd });
                loadMessages(recipient, true);
                return;
            }

            if (!message) return;
            
            // 1. Instant 0ms Screen Visual Push
            const optimisticId = 'temp_' + Date.now();
            const tempMsgObj = {
                id: optimisticId,
                recipient_phone: recipient,
                direction: 'outbound',
                message_text: message,
                sent_via: channel,
                status: 'sent',
                created_at: new Date().toISOString()
            };
            currentLoadedMessages.push(tempMsgObj);
            renderMessagesStream(currentLoadedMessages, true);
            msgInput.value = '';

            // 2. Background Auto-Save to Local Cache (No refresh button required)
            const last10Key = recipient.slice(-10);
            try {
                let existingLocal = [];
                const rawC = localStorage.getItem('wuba_chat_' + last10Key) || localStorage.getItem('wuba_chat_' + recipient);
                if (rawC) existingLocal = JSON.parse(rawC) || [];
                existingLocal.push(tempMsgObj);
                localStorage.setItem('wuba_chat_' + last10Key, JSON.stringify(existingLocal));
                localStorage.setItem('wuba_chat_' + recipient, JSON.stringify(existingLocal));
            } catch(e) {}

            const fd = new FormData();
            fd.append('recipient', recipient);
            fd.append('message', message);
            fd.append('sent_via', channel);
            fd.append('mode', 'text');
            if (replyWamid) {
                fd.append('reply_to_wamid', replyWamid);
            }

            try {
                const res = await fetch('api/send_message.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status) {
                    msgInput.value = '';
                    cancelReplyMode();
                    loadMessages(recipient, false);
                    if (data.new_balance !== undefined) {
                        document.getElementById('top-wallet-display').innerText = '₹' + parseFloat(data.new_balance).toFixed(2);
                    }
                } else {
                    const errLower = (data.message || '').toLowerCase();
                    if (errLower.includes('balance') || errLower.includes('insufficient') || errLower.includes('credit')) {
                        showToast('Low Balance! Please recharge to send message.', true);
                        openRechargeModal();
                        // Optimistic bubble revert karein
                        loadMessages(recipient, false);
                    } else {
                        alert(data.message);
                    }
                }
            } catch(e) { 
                loadMessages(recipient, false);
            }
        }

        function openSaveHeaderContactModal() {
            let p = currentSelectedPhone;
            if (p.startsWith('91') && p.length === 12) p = p.substr(2);
            document.getElementById('modal-contact-phone').value = p;
            document.getElementById('modal-contact-name').value = '';
            openModal();
        }

        /* -------------------------------------------------------------
           LOAD & RENDER PIPELINE WITH CACHE SYNC
           ------------------------------------------------------------- */
        async function loadMessages(phone, autoScroll = true) {
            if (isFetchingMessages) return;
            isFetchingMessages = true;
            let cleanPhone = '';

            try {
                cleanPhone = phone.replace(/\D/g, '');
                const last10 = cleanPhone.slice(-10);

                // 1. Pehle local storage se instant purana cache dikhao
                let savedList = [];
                try {
                    const localRaw = localStorage.getItem('wuba_chat_' + last10) || localStorage.getItem('wuba_chat_' + cleanPhone);
                    if (localRaw) savedList = JSON.parse(localRaw) || [];
                } catch(e) {}

                // 2. Server se is chat ke latest messages maango (Bina kisi blocking filter ke)
                let url = `api/fetch_messages.php?phone=${encodeURIComponent(cleanPhone)}`;
                const res = await fetch(url, { signal: activeChatController ? activeChatController.signal : null });
                const data = await res.json();
                if (!data.status) return;

                if (currentSelectedPhone.slice(-10) !== last10) return;

                const incoming = data.messages || [];

                // 3. Purane aur naye messages ko merge karo (Deduplication)
                const msgMap = new Map();
                savedList.forEach(m => msgMap.set(String(m.id), m));
                incoming.forEach(m => msgMap.set(String(m.id), m));

                const merged = Array.from(msgMap.values()).sort((a, b) => {
                    let tA = a.created_at ? new Date(a.created_at.replace(' ', 'T')).getTime() : 0;
                    let tB = b.created_at ? new Date(b.created_at.replace(' ', 'T')).getTime() : 0;
                    return tA - tB;
                });

                // 4. Dono keys me save karo taaki photo kabhi gayab na ho
                try {
                    localStorage.setItem('wuba_chat_' + last10, JSON.stringify(merged));
                    localStorage.setItem('wuba_chat_' + cleanPhone, JSON.stringify(merged));
                } catch(e) {}
                
                currentLoadedMessages = merged;
                renderMessagesStream(merged, autoScroll);
            } catch(e) {}
            finally {
                isFetchingMessages = false;
                const selectedPhone = currentSelectedPhone.replace(/\D/g, '');
                if (cleanPhone && selectedPhone && selectedPhone.slice(-10) !== cleanPhone.slice(-10)) {
                    loadMessages(selectedPhone, false);
                }
            }
        }

        function updateMessageStatusBadges(messagesArray) {
            const messagesById = new Map(messagesArray.map(message => [String(message.id), message]));
            document.querySelectorAll('[data-message-status]').forEach(statusElement => {
                const message = messagesById.get(statusElement.dataset.messageStatus);
                if (!message || message.direction !== 'outbound') return;

                const status = (message.status || 'sent').toLowerCase();
                if (statusElement.dataset.status === status) return;

                let icon = '<i class="fa-solid fa-check text-gray-400 ml-1"></i>';
                if (status === 'delivered') {
                    icon = '<i class="fa-solid fa-check-double text-gray-400 ml-1"></i>';
                } else if (status === 'read') {
                    icon = '<i class="fa-solid fa-check-double text-[#53bdeb] ml-1"></i>';
                }

                statusElement.dataset.status = status;
                statusElement.innerHTML = icon;
            });
        }

        function renderMessagesStream(messagesArray, autoScroll = true) {
            currentLoadedMessages = messagesArray || [];
            currentGalleryImages = [];

            const stream = document.getElementById('messages-stream');
            const lastMessage = currentLoadedMessages[currentLoadedMessages.length - 1];
            const renderSignature = JSON.stringify([
                currentSelectedPhone.slice(-10),
                isSelectMode,
                Array.from(selectedMessageIds).map(String).sort(),
                currentLoadedMessages.length,
                lastMessage ? String(lastMessage.id) : '',
                lastMessage ? (lastMessage.created_at || '') : '',
                currentLoadedMessages.map(message => [
                    String(message.id),
                    message.created_at || '',
                    message.message_text || '',
                    message.direction || '',
                    message.wamid || '',
                    message.reply_to_wamid || '',
                    message.reaction || ''
                ])
            ]);

            if (renderSignature === lastMessagesJSON) {
                updateMessageStatusBadges(currentLoadedMessages);

                if (targetScrollMatchId) {
                    const targetEl = document.getElementById(`msg-bubble-${targetScrollMatchId}`);
                    if (targetEl) {
                        targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        targetEl.classList.add('message-jump-focus');
                    }
                    targetScrollMatchId = null;
                } else if (autoScroll && currentLoadedMessages.length > 0) {
                    stream.scrollTop = stream.scrollHeight;
                }
                return;
            }
            lastMessagesJSON = renderSignature;

            if (!currentLoadedMessages || currentLoadedMessages.length === 0) {
                stream.innerHTML = `<div class="flex justify-center my-4"><span class="bg-white/80 text-gray-500 text-[11px] px-3 py-1 rounded-full shadow-sm">No messages yet.</span></div>`;
                return;
            }

            const msgMapByWamid = {};
            currentLoadedMessages.forEach(msg => {
                if (msg.wamid) {
                    msgMapByWamid[msg.wamid] = msg;
                }
            });

            let groupedBlocks = [];
            let currentImageCluster = null;

            currentLoadedMessages.forEach(m => {
                const raw = m.message_text || '';
                const isImg = raw.startsWith('[Image]:');

                if (isImg) {
                    if (currentImageCluster && currentImageCluster.direction === m.direction) {
                        currentImageCluster.items.push(m);
                    } else {
                        if (currentImageCluster) groupedBlocks.push(currentImageCluster);
                        currentImageCluster = { type: 'album', direction: m.direction, items: [m] };
                    }
                } else {
                    if (currentImageCluster) {
                        groupedBlocks.push(currentImageCluster);
                        currentImageCluster = null;
                    }
                    groupedBlocks.push({ type: 'single', message: m });
                }
            });
            if (currentImageCluster) groupedBlocks.push(currentImageCluster);

            let html = '';
            let lastRenderedDate = null;

            groupedBlocks.forEach(block => {
                let blockDateStr = '';
                if (block.type === 'album') {
                    blockDateStr = block.items[0]?.created_at || '';
                } else {
                    blockDateStr = block.message?.created_at || '';
                }

                if (blockDateStr) {
                    let dateLabel = getMessageDateGroupLabel(blockDateStr);
                    if (dateLabel && dateLabel !== lastRenderedDate) {
                        html += `
                            <div class="flex justify-center my-3 select-none">
                                <span class="bg-white/90 backdrop-blur-xs text-gray-600 text-[11px] font-bold px-3 py-1 rounded-lg shadow-xs uppercase tracking-wide border border-gray-100">
                                    ${dateLabel}
                                </span>
                            </div>
                        `;
                        lastRenderedDate = dateLabel;
                    }
                }

                if (block.type === 'album') {
                    const isOut = (block.direction === 'outbound');
                    const items = block.items;
                    const totalImages = items.length;
                    const albumIds = items.map(x => x.id);

                    items.forEach(itm => {
                        const u = itm.message_text.replace('[Image]:', '').trim();
                        currentGalleryImages.push({ id: itm.id, url: u });
                    });

                    const firstGallIdx = currentGalleryImages.length - totalImages;

                    let gridLayoutClass = 'grid-cols-2';
                    if (totalImages === 1) gridLayoutClass = 'grid-cols-1';
                    else if (totalImages === 3) gridLayoutClass = 'grid-cols-2';

                    let imagesHtml = '';
                    const maxShow = 4;
                    const displayItems = items.slice(0, maxShow);

                    displayItems.forEach((itm, idx) => {
                        const u = itm.message_text.replace('[Image]:', '').trim();
                        const actualIdx = firstGallIdx + idx;
                        const isLastWithMore = (idx === maxShow - 1 && totalImages > maxShow);
                        const extraCount = totalImages - maxShow;

                        imagesHtml += `
                            <div class="relative overflow-hidden rounded-xl bg-black/10 aspect-square cursor-pointer group/img" onclick="openGalleryLightbox(${actualIdx})">
                                <img src="${u}" class="w-full h-full object-cover hover:scale-105 transition duration-200">
                                ${isLastWithMore ? `
                                    <div class="absolute inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center text-white font-extrabold text-base">
                                        +${extraCount + 1}
                                    </div>
                                ` : ''}
                            </div>
                        `;
                    });

                    const lastMsgTime = items[items.length - 1].created_at ? formatTimeISTJS(items[items.length - 1].created_at) : '';
                    const firstMsg = items[0];
                    const wamidVal = firstMsg.wamid || '';
                    const snippetText = `[Album: ${totalImages} Photos]`;

                    let lastMsgStatus = (items[items.length - 1].status || 'sent').toLowerCase();
                    let albumTickIcon = '<i class="fa-solid fa-check text-gray-400 ml-1"></i>';
                    if (lastMsgStatus === 'delivered') {
                        albumTickIcon = '<i class="fa-solid fa-check-double text-gray-400 ml-1"></i>';
                    } else if (lastMsgStatus === 'read') {
                        albumTickIcon = '<i class="fa-solid fa-check-double text-[#53bdeb] ml-1"></i>';
                    }

                    html += `
                        <div class="w-full flex ${isOut ? 'justify-end' : 'justify-start'} my-1.5 group select-text" id="msg-bubble-${firstMsg.id}">
                            ${isSelectMode ? `
                                <div class="flex items-center ${isOut ? 'ml-2 order-last' : 'mr-2 order-first'}">
                                    <input type="checkbox" onchange="toggleAlbumSelection(${JSON.stringify(albumIds)})" class="w-4 h-4 rounded-full text-[#00a884] focus:ring-0 cursor-pointer">
                                </div>
                            ` : ''}

                            <div class="relative max-w-[85%] sm:max-w-md flex flex-col ${isOut ? 'items-end' : 'items-start'}">
                                <div class="absolute -top-9 ${isOut ? 'right-0' : 'left-0'} bg-white border border-gray-200 shadow-md rounded-xl px-2 py-1 flex items-center space-x-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-30">
                                    <button type="button" onclick="triggerReplyMessage('${wamidVal}', '${escapeHtml(addslashes(snippetText))}')" class="text-gray-500 hover:text-[#00a884] transition p-1" title="Reply"><i class="fa-solid fa-reply"></i></button>
                                    <button type="button" onclick="copyMessageText(event, '${escapeHtml(addslashes(snippetText))}')" class="text-gray-500 hover:text-[#00a884] transition p-1" title="Copy Text"><i class="fa-solid fa-copy"></i></button>
                                </div>

                                <div class="${isOut ? 'bg-[#d9fdd3]' : 'bg-white'} p-2 rounded-2xl shadow-sm border border-gray-100/60 text-left">
                                    <div class="flex items-center justify-between pb-1.5 px-1 border-b border-gray-100 text-[11px] font-bold text-gray-500 mb-1">
                                        <span>📷 ${totalImages} Photos</span>
                                        <div class="flex items-center space-x-2">
                                            <button type="button" onclick='downloadAlbumImages(${JSON.stringify(items.map(x => x.message_text.replace("[Image]:", "").trim()))})' class="hover:text-emerald-600 transition" title="Download All Photos"><i class="fa-solid fa-download"></i></button>
                                            <button type="button" onclick='forwardAlbumImages(${JSON.stringify(albumIds)})' class="hover:text-blue-600 transition" title="Forward All Photos"><i class="fa-solid fa-share"></i></button>
                                            <button type="button" onclick='deleteAlbumImages(${JSON.stringify(albumIds)})' class="hover:text-red-600 transition" title="Delete All Photos"><i class="fa-solid fa-trash-can"></i></button>
                                        </div>
                                    </div>
                                    <div class="grid ${gridLayoutClass} gap-1 max-w-[280px]">
                                        ${imagesHtml}
                                    </div>
                                    <div class="text-[9px] text-gray-400 text-right mt-1 flex items-center justify-end space-x-0.5">
                                        <span>${lastMsgTime}</span>
                                        ${isOut ? `<span data-message-status="${items[items.length - 1].id}" data-status="${lastMsgStatus}">${albumTickIcon}</span>` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    const m = block.message;
                    const isOut = (m.direction === 'outbound');
                    let rawContent = m.message_text || '';
                    let content = escapeHtml(rawContent);

                    if (rawContent.startsWith('[Image]:')) {
                        const url = rawContent.replace('[Image]:', '').trim();
                        currentGalleryImages.push({ id: m.id, url: url });
                        const gIdx = currentGalleryImages.length - 1;
                        content = `
                            <div class="relative group/media my-1 rounded-2xl overflow-hidden border border-gray-200/60 bg-black/5 cursor-pointer" onclick="openGalleryLightbox(${gIdx})">
                                <img src="${url}" class="rounded-xl max-h-56 max-w-xs object-cover hover:opacity-95 transition">
                            </div>
                        `;
                    } else if (rawContent.startsWith('[Document]:')) {
                        const url = rawContent.replace('[Document]:', '').trim();
                        const fileName = url.split('/').pop().split('?')[0] || 'Document File';
                        const fileExt = fileName.split('.').pop().toLowerCase();
                        
                        let docIcon = 'fa-file-lines text-blue-500';
                        if (['xlsx', 'xls', 'csv'].includes(fileExt)) {
                            docIcon = 'fa-file-excel text-emerald-600';
                        } else if (fileExt === 'pdf') {
                            docIcon = 'fa-file-pdf text-red-500';
                        } else if (['doc', 'docx'].includes(fileExt)) {
                            docIcon = 'fa-file-word text-blue-600';
                        }

                        content = `
                            <div class="flex items-center justify-between p-2.5 bg-gray-50 border rounded-2xl my-1 space-x-3">
                                <div class="flex items-center space-x-2 truncate">
                                    <i class="fa-solid ${docIcon} text-xl shrink-0"></i>
                                    <span class="text-xs font-semibold truncate max-w-[140px]">${escapeHtml(fileName)}</span>
                                </div>
                                <a href="api/fetch_messages.php?action=download_stream&url=${encodeURIComponent(url)}" target="_blank" download class="text-xs bg-[#00a884] text-white px-3 py-1 rounded-xl font-bold shadow-sm flex items-center space-x-1 shrink-0">
                                    <i class="fa-solid fa-download text-[10px]"></i> <span>Save</span>
                                </a>
                            </div>
                        `;
                    } else if (rawContent.startsWith('[Audio]:')) {
                        const url = rawContent.replace('[Audio]:', '').trim();
                        content = `
                            <div class="my-1">
                                <audio controls class="w-56 h-8">
                                    <source src="${url}" type="audio/mpeg">
                                    <source src="${url}" type="audio/webm">
                                    Your browser does not support the audio element.
                                </audio>
                            </div>
                        `;
                    } else if (rawContent.startsWith('[Video]:')) {
                        const url = rawContent.replace('[Video]:', '').split('\n')[0].trim();
                        content = `
                            <div class="my-1 rounded-2xl overflow-hidden border border-gray-200/60 bg-black/5">
                                <video controls class="rounded-xl max-h-56 max-w-xs object-cover w-full">
                                    <source src="${url}" type="video/mp4">
                                    Your browser does not support video.
                                </video>
                            </div>
                        `;
                    } else if (rawContent.startsWith('[Sticker]:')) {
                        const url = rawContent.replace('[Sticker]:', '').trim();
                        content = `
                            <div class="my-1">
                                <img src="${url}" class="max-h-32 max-w-32 object-contain">
                            </div>
                        `;
                    } else if (rawContent.includes('[Location]:')) {
                        content = `
                            <div class="p-2.5 bg-emerald-50 rounded-xl text-xs font-bold text-emerald-900 border border-emerald-200 flex items-center space-x-2">
                                <i class="fa-solid fa-map-location-dot text-lg text-[#00a884]"></i>
                                <span>${escapeHtml(rawContent)}</span>
                            </div>
                        `;
                    }

                    let quoteBoxHtml = '';
                    if (m.reply_to_wamid && msgMapByWamid[m.reply_to_wamid]) {
                        const parentMsg = msgMapByWamid[m.reply_to_wamid];
                        const parentSnippet = escapeHtml(parentMsg.message_text || 'Media Message').substring(0, 60);
                        const parentSender = (parentMsg.direction === 'outbound') ? 'You' : 'User';
                        quoteBoxHtml = `
                        <div onclick="jumpToQuotedMessage('${parentMsg.id}')" class="bg-black/5 hover:bg-black/10 cursor-pointer border-l-4 border-[#00a884] p-1.5 mb-1.5 rounded-lg text-[11px] select-none transition group/quote" title="Click to view original message">
                            <span class="font-bold text-[#00a884] flex items-center justify-between">
                                <span>${parentSender}</span>
                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-40 group-hover/quote:opacity-100 transition"></i>
                            </span>
                            <p class="text-gray-600 truncate">${parentSnippet}</p>
                        </div>
                    `;
                    }

                    let tickIcon = '';
                    if (isOut) {
                        const st = (m.status || 'sent').toLowerCase();
                        if (st === 'read') {
                            tickIcon = '<i class="fa-solid fa-check-double text-[#53bdeb] ml-1"></i>';
                        } else if (st === 'delivered') {
                            tickIcon = '<i class="fa-solid fa-check-double text-gray-400 ml-1"></i>';
                        } else {
                            tickIcon = '<i class="fa-solid fa-check text-gray-400 ml-1"></i>';
                        }
                    }

                    const reactionBadge = m.reaction ? `<span class="absolute -bottom-2.5 right-2 bg-white rounded-full px-1.5 py-0.5 text-xs shadow-md border border-gray-100">${m.reaction}</span>` : '';
                    const isChecked = selectedMessageIds.has(m.id);
                    const wamidVal = m.wamid || '';
                    const msgTimeIST = m.created_at ? formatTimeISTJS(m.created_at) : '';

                    html += `
                        <div class="w-full flex ${isOut ? 'justify-end' : 'justify-start'} my-1.5 group select-text" id="msg-bubble-${m.id}">
                            ${isSelectMode ? `
                                <div class="flex items-center ${isOut ? 'ml-2 order-last' : 'mr-2 order-first'}">
                                    <input type="checkbox" onchange="toggleSelectMessageId(${m.id})" ${isChecked ? 'checked' : ''} class="w-4 h-4 rounded-full text-[#00a884] focus:ring-0 cursor-pointer">
                                </div>
                            ` : ''}

                            <div class="relative max-w-[85%] sm:max-w-md flex flex-col ${isOut ? 'items-end' : 'items-start'}">
                                <div class="absolute -top-9 ${isOut ? 'right-0' : 'left-0'} bg-white border border-gray-200 shadow-md rounded-xl px-2 py-1 flex items-center space-x-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-30">
                                    <button type="button" onclick="triggerReplyMessage('${wamidVal}', '${escapeHtml(addslashes(rawContent))}')" class="text-gray-500 hover:text-[#00a884] transition p-1" title="Reply"><i class="fa-solid fa-reply"></i></button>
                                    <button type="button" onclick="copyMessageText(event, '${escapeHtml(addslashes(rawContent))}')" class="text-gray-500 hover:text-[#00a884] transition p-1" title="Copy Text"><i class="fa-solid fa-copy"></i></button>
                                    <button type="button" onclick="showQuickReaction(event, ${m.id})" class="text-gray-500 hover:text-amber-500 transition p-1" title="React"><i class="fa-regular fa-face-smile"></i></button>
                                    <button type="button" onclick="translateSingleMessage(event, ${m.id}, '${encodeURIComponent(rawContent)}')" class="text-gray-500 hover:text-[#00a884] transition p-1 translate-trigger-btn" title="Translate Message"><i class="fa-solid fa-language"></i></button>
                                    <button type="button" onclick='openMessageInfoModal(${JSON.stringify(m)})' class="text-gray-500 hover:text-blue-500 transition p-1" title="Message Info"><i class="fa-solid fa-circle-info"></i></button>
                                    <button type="button" onclick="triggerDeleteSingleMessage(${m.id}, ${isOut ? 'true' : 'false'})" class="text-gray-500 hover:text-red-500 transition p-1" title="Delete Message"><i class="fa-solid fa-trash-can"></i></button>
                                </div>

                                <div class="${isOut ? 'bg-[#d9fdd3]' : 'bg-white'} px-3 py-1.5 rounded-2xl shadow-sm text-xs border border-gray-100/60 text-left min-w-[80px]">
                                    ${quoteBoxHtml}
                                    <div class="msg-content whitespace-pre-wrap leading-relaxed break-words">${content}</div>
                                    <div class="text-[9px] text-gray-400 text-right mt-1 flex items-center justify-end space-x-0.5">
                                        <span>${msgTimeIST}</span>
                                        ${isOut ? `<span data-message-status="${m.id}" data-status="${(m.status || 'sent').toLowerCase()}">${tickIcon}</span>` : ''}
                                    </div>
                                    ${reactionBadge}
                                </div>
                            </div>
                        </div>
                    `;
                }
            });

            stream.innerHTML = html;

            if (targetScrollMatchId) {
                const targetEl = document.getElementById(`msg-bubble-${targetScrollMatchId}`);
                if (targetEl) {
                    targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    targetEl.classList.add('message-jump-focus');
                }
                targetScrollMatchId = null;
            } else if (autoScroll) {
                stream.scrollTop = stream.scrollHeight;
            }
        }

        function copyMessageText(e, text) {
            e.stopPropagation();
            let cleanText = text;
            if (text.startsWith('[Image]:')) cleanText = text.split('\n')[1] || text;
            navigator.clipboard.writeText(cleanText);
            alert('Message copied to clipboard!');
        }

        // Live Periodic Background Sync for Contacts Drawer
        setInterval(async () => {
            if (isSyncingContacts) return;
            isSyncingContacts = true;

            try {
                const res = await fetch('api/chat_actions.php?action=get_contacts_sync');
                const d = await res.json();
                if (d.status && d.contacts) {
                    const currentSig = JSON.stringify(d.contacts.map(x => [x.phone_number, x.last_msg, x.last_time, x.unread_count]));
                    
                    if (currentSig !== lastSyncSignature) {
                        lastSyncSignature = currentSig;
                        savedContactsList = d.contacts;

                        const searchInput = document.getElementById('contact-search');
                        if (!searchInput || !searchInput.value.trim()) {
                            renderDefaultContactsList();
                        }
                    }

                    const badge = document.getElementById('sidebar-unread-badge');
                    if (badge) {
                        if (d.total_unread > 0) {
                            badge.innerText = d.total_unread;
                            badge.classList.remove('hidden');
                        } else {
                            badge.classList.add('hidden');
                        }
                    }
                }
            } catch(e) {}
            finally {
                isSyncingContacts = false;
            }
        }, 8000);

        async function triggerDeleteSingleMessage(msgId, isSender) {
            let choice = 'me';
            if (isSender) {
                const ans = prompt("Type 'everyone' to Delete for Everyone, or 'me' to Delete for Me only:", "everyone");
                if (!ans) return;
                choice = (ans.trim().toLowerCase() === 'everyone') ? 'everyone' : 'me';
            } else {
                if (!confirm("Delete this message for yourself?")) return;
            }

            const fd = new FormData();
            fd.append('action', 'delete_message_option');
            fd.append('message_id', msgId);
            fd.append('delete_type', choice);

            try {
                const res = await fetch('api/chat_actions.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status) {
                    lastMessagesJSON = '';
                    loadMessages(currentSelectedPhone, false);
                } else {
                    alert(data.message || 'Unable to delete message.');
                }
            } catch(e) {
                alert('Network error while deleting message.');
            }
        }

        function triggerReplyMessage(wamid, textSnippet) {
            if (!wamid) {
                alert('This message cannot be quoted (Missing WhatsApp ID).');
                return;
            }
            document.getElementById('reply-wamid-input').value = wamid;
            document.getElementById('reply-preview-text').innerText = textSnippet;
            document.getElementById('reply-preview-banner').classList.remove('hidden');
            document.getElementById('reply-preview-banner').classList.add('flex');
            document.getElementById('target-message-input').focus();
        }

        function cancelReplyMode() {
            document.getElementById('reply-wamid-input').value = '';
            document.getElementById('reply-preview-text').innerText = '';
            document.getElementById('reply-preview-banner').classList.add('hidden');
            document.getElementById('reply-preview-banner').classList.remove('flex');
        }

        function openMessageInfoModal(msg) {
            document.getElementById('msg-info-preview-text').innerText = msg.message_text || '';
            const isOut = (msg.direction === 'outbound');
            const fullDateTimeStr = msg.created_at ? formatDateTimeISTJS(msg.created_at) : 'Just now';
            const status = (msg.status || 'sent').toUpperCase();

            document.getElementById('msg-info-sent-time').innerText = fullDateTimeStr;
            document.getElementById('msg-info-delivered-time').innerText = (status === 'DELIVERED' || status === 'READ') ? fullDateTimeStr : 'Pending';
            document.getElementById('msg-info-read-time').innerText = (status === 'READ') ? fullDateTimeStr : 'Not read yet';

            const badge = document.getElementById('msg-info-status-badge');
            badge.innerText = status;
            badge.className = isOut ? 'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800' : 'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-800';

            document.getElementById('message-info-modal').classList.remove('hidden');
            document.getElementById('message-info-modal').classList.add('flex');
        }

        function closeMessageInfoModal() {
            document.getElementById('message-info-modal').classList.add('hidden');
            document.getElementById('message-info-modal').classList.remove('flex');
        }

        function searchInChatMessages() {
            const q = (document.getElementById('chat-search-input').value || '').trim().toLowerCase();
            const stream = document.getElementById('messages-stream');
            const bubbles = stream.querySelectorAll('[id^="msg-bubble-"]');

            bubbles.forEach(w => {
                const text = w.innerText.toLowerCase();
                const contentEl = w.querySelector('.msg-content');
                if (!contentEl) return;

                if (q.length > 0 && text.includes(q)) {
                    w.style.display = 'flex';
                    const regex = new RegExp(`(${q})`, 'gi');
                    contentEl.innerHTML = escapeHtml(contentEl.innerText).replace(regex, '<span class="highlight-match">$1</span>');
                } else if (q.length > 0) {
                    w.style.display = 'none';
                } else {
                    w.style.display = 'flex';
                    contentEl.innerHTML = escapeHtml(contentEl.innerText);
                }
            });
        }

        function exportCurrentChatHistory() {
            if (!currentSelectedPhone || currentLoadedMessages.length === 0) {
                alert('No active conversation to export.');
                return;
            }
            let output = `WhatsApp Chat Export with +${currentSelectedPhone}\n\n`;
            currentLoadedMessages.forEach(m => {
                const sender = (m.direction === 'outbound') ? 'You' : ('+' + currentSelectedPhone);
                const time = m.created_at || '';
                output += `[${time}] ${sender}: ${m.message_text}\n`;
            });

            const blob = new Blob([output], { type: 'text/plain;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `WhatsApp_Chat_${currentSelectedPhone}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        function downloadAlbumImages(urls) {
            if (!urls || urls.length === 0) return;
            urls.forEach((url, i) => {
                setTimeout(() => {
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'photo_' + (i + 1);
                    a.target = '_blank';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                }, i * 200);
            });
        }

        function forwardAlbumImages(ids) {
            selectedMessageIds.clear();
            ids.forEach(id => selectedMessageIds.add(id));
            openForwardModal();
        }

        async function deleteAlbumImages(ids) {
            if (!confirm(`Delete all ${ids.length} photos in this album?`)) return;
            for (let id of ids) {
                const fd = new FormData();
                fd.append('action', 'delete_message');
                fd.append('message_id', id);
                await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            }
            lastMessagesJSON = '';
            loadMessages(currentSelectedPhone, false);
        }

        function toggleSelectMode() {
            isSelectMode = !isSelectMode;
            selectedMessageIds.clear();
            const bar = document.getElementById('multi-select-bar');
            const form = document.getElementById('single-chat-form');
            if (isSelectMode) {
                bar.classList.remove('hidden');
                form.classList.add('hidden');
            } else {
                bar.classList.add('hidden');
                form.classList.remove('hidden');
            }
            updateSelectedCountDisplay();
            lastMessagesJSON = '';
            loadMessages(currentSelectedPhone, false);
        }

        function toggleSelectMessageId(id) {
            if (selectedMessageIds.has(id)) selectedMessageIds.delete(id);
            else selectedMessageIds.add(id);
            updateSelectedCountDisplay();
        }

        function toggleAlbumSelection(ids) {
            let allSelected = ids.every(id => selectedMessageIds.has(id));
            ids.forEach(id => {
                if (allSelected) selectedMessageIds.delete(id);
                else selectedMessageIds.add(id);
            });
            updateSelectedCountDisplay();
        }

        function updateSelectedCountDisplay() {
            document.getElementById('selected-messages-count').innerText = `${selectedMessageIds.size} selected`;
        }

        async function deleteSelectedMessages() {
            if (selectedMessageIds.size === 0) { alert('No messages selected.'); return; }
            if (!confirm(`Delete ${selectedMessageIds.size} selected message(s)?`)) return;

            for (let id of selectedMessageIds) {
                const fd = new FormData();
                fd.append('action', 'delete_message');
                fd.append('message_id', id);
                await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            }
            toggleSelectMode();
            lastMessagesJSON = '';
            loadMessages(currentSelectedPhone, false);
        }

        function downloadSelectedMedia() {
            if (selectedMessageIds.size === 0) { alert('No messages selected.'); return; }
            let count = 0;
            currentLoadedMessages.forEach(m => {
                if (selectedMessageIds.has(m.id)) {
                    const text = m.message_text || '';
                    if (text.startsWith('[Image]:') || text.startsWith('[Document]:') || text.startsWith('[Audio]:')) {
                        const url = text.split(']:')[1].trim();
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'media_' + m.id;
                        a.target = '_blank';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        count++;
                    }
                }
            });
            if (count === 0) alert('None of the selected messages contain downloadable media files.');
        }

        function openForwardModal() {
            if (selectedMessageIds.size === 0) { alert('Please select messages to forward.'); return; }
            forwardSelectedContacts.clear();
            document.getElementById('forward-modal').classList.remove('hidden');
            document.getElementById('forward-modal').classList.add('flex');
            renderForwardContactsList();
        }

        function closeForwardModal() {
            document.getElementById('forward-modal').classList.add('hidden');
            document.getElementById('forward-modal').classList.remove('flex');
        }

        function renderForwardContactsList() {
            const query = (document.getElementById('forward-search-input').value || '').toLowerCase();
            const container = document.getElementById('forward-contacts-container');
            const filtered = savedContactsList.filter(c => (c.contact_name && c.contact_name.toLowerCase().includes(query)) || (c.phone_number && c.phone_number.includes(query)));

            container.innerHTML = filtered.map(c => `
                <label class="flex items-center justify-between p-2.5 hover:bg-gray-50 rounded-2xl cursor-pointer transition">
                    <div class="flex items-center space-x-3 truncate">
                        <div class="w-9 h-9 bg-teal-100 text-teal-800 font-bold rounded-full flex items-center justify-center text-xs uppercase">
                            ${c.contact_name.substr(0, 2).toUpperCase()}
                        </div>
                        <div class="truncate">
                            <h4 class="text-xs font-bold text-gray-800 truncate">${escapeHtml(c.contact_name)}</h4>
                            <p class="text-[10px] text-gray-400 font-mono">+${c.phone_number}</p>
                        </div>
                    </div>
                    <input type="checkbox" onchange="toggleForwardContact('${c.phone_number}')" ${forwardSelectedContacts.has(c.phone_number) ? 'checked' : ''} class="w-4 h-4 rounded text-[#00a884] focus:ring-0">
                </label>
            `).join('');
            document.getElementById('forward-selected-count').innerText = `${forwardSelectedContacts.size} contacts selected`;
        }

        function toggleForwardContact(phone) {
            if (forwardSelectedContacts.has(phone)) forwardSelectedContacts.delete(phone);
            else forwardSelectedContacts.add(phone);
            document.getElementById('forward-selected-count').innerText = `${forwardSelectedContacts.size} contacts selected`;
        }

        async function dispatchForwardMessages() {
            if (forwardSelectedContacts.size === 0) { alert('Select at least one contact.'); return; }
            
            const messagesToForward = currentLoadedMessages.filter(m => selectedMessageIds.has(m.id));
            if (messagesToForward.length === 0) return;

            const sendBtn = document.getElementById('forward-submit-btn');
            const btnText = document.getElementById('forward-btn-text');
            const btnIcon = document.getElementById('forward-btn-icon');

            sendBtn.disabled = true;
            btnText.innerText = 'Sending...';
            btnIcon.className = 'fa-solid fa-circle-notch fa-spin text-[11px]';

            const dispatchTasks = [];

            for (let phone of forwardSelectedContacts) {
                for (let msg of messagesToForward) {
                    const raw = (msg.message_text || '').trim();
                    const fd = new FormData();
                    fd.append('recipient', phone);
                    fd.append('sent_via', 'whatsapp');

                    if (raw.startsWith('[Image]:')) {
                        const url = raw.replace('[Image]:', '').trim();
                        fd.append('media_type', 'image');
                        fd.append('media_url', url);
                        dispatchTasks.push(fetch('api/upload_media.php', { method: 'POST', body: fd }));
                    } else if (raw.startsWith('[Document]:')) {
                        const url = raw.replace('[Document]:', '').trim();
                        fd.append('media_type', 'document');
                        fd.append('media_url', url);
                        dispatchTasks.push(fetch('api/upload_media.php', { method: 'POST', body: fd }));
                    } else if (raw.startsWith('[Audio]:')) {
                        const url = raw.replace('[Audio]:', '').trim();
                        fd.append('media_type', 'audio');
                        fd.append('media_url', url);
                        dispatchTasks.push(fetch('api/upload_media.php', { method: 'POST', body: fd }));
                    } else {
                        fd.append('message', raw);
                        dispatchTasks.push(fetch('api/send_message.php', { method: 'POST', body: fd }));
                    }
                }
            }

            try {
                await Promise.all(dispatchTasks);
                alert('Messages forwarded successfully!');
            } catch(e) {
                alert('Error forwarding messages. Please try again.');
            } finally {
                sendBtn.disabled = false;
                btnText.innerText = 'Send';
                btnIcon.className = 'fa-solid fa-paper-plane text-[11px]';
                closeForwardModal();
                toggleSelectMode();
            }
        }

        function handleDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!currentSelectedPhone) return;
            document.getElementById('drag-drop-overlay').classList.remove('hidden');
            document.getElementById('drag-drop-overlay').classList.add('flex');
        }

        function handleDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('drag-drop-overlay').classList.add('hidden');
            document.getElementById('drag-drop-overlay').classList.remove('flex');
        }

        async function handleDropMedia(e) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('drag-drop-overlay').classList.add('hidden');
            document.getElementById('drag-drop-overlay').classList.remove('flex');

            if (!currentSelectedPhone) {
                alert('Please select a chat first to drop files.');
                return;
            }

            const files = e.dataTransfer.files;
            if (!files || files.length === 0) return;

            const replyWamid = document.getElementById('reply-wamid-input').value.trim();
            const channel = isCurrentChatWubachatUser ? 'wubachat' : 'whatsapp';

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fd = new FormData();
                fd.append('recipient', currentSelectedPhone);
                fd.append('file', file);
                fd.append('sent_via', channel);
                if (replyWamid) fd.append('reply_to_wamid', replyWamid);

                let mType = 'document';
                if (file.type.startsWith('image/') || file.type.startsWith('video/')) {
                    mType = 'image';
                } else if (file.type.startsWith('audio/')) {
                    mType = 'audio';
                }
                fd.append('media_type', mType);

                await fetch('api/upload_media.php', { method: 'POST', body: fd });
            }
            cancelReplyMode();
            lastMessagesJSON = '';
            loadMessages(currentSelectedPhone, false);
        }

        function openGalleryLightbox(index) {
            if (!currentGalleryImages || currentGalleryImages.length === 0) return;
            currentGalleryIndex = index;
            updateGalleryLightboxView();
            document.getElementById('gallery-lightbox').classList.remove('hidden');
            document.getElementById('gallery-lightbox').classList.add('flex');
        }

        function closeGalleryLightbox() {
            document.getElementById('gallery-lightbox').classList.add('hidden');
            document.getElementById('gallery-lightbox').classList.remove('flex');
        }

        function updateGalleryLightboxView() {
            const cur = currentGalleryImages[currentGalleryIndex];
            if (!cur) return;
            document.getElementById('gallery-preview-img').src = cur.url;
            document.getElementById('gallery-download-btn').href = cur.url;
            document.getElementById('gallery-image-index').innerText = `Image ${currentGalleryIndex + 1} of ${currentGalleryImages.length}`;
        }

        function nextGalleryImage() {
            if (currentGalleryIndex < currentGalleryImages.length - 1) {
                currentGalleryIndex++;
                updateGalleryLightboxView();
            }
        }

        function prevGalleryImage() {
            if (currentGalleryIndex > 0) {
                currentGalleryIndex--;
                updateGalleryLightboxView();
            }
        }

        function forwardSingleGalleryImage() {
            const cur = currentGalleryImages[currentGalleryIndex];
            if (cur) forwardAlbumImages([cur.id]);
        }

        window.addEventListener('keydown', function(e) {
            if (document.getElementById('gallery-lightbox').classList.contains('flex')) {
                if (e.key === 'ArrowRight') nextGalleryImage();
                if (e.key === 'ArrowLeft') prevGalleryImage();
                if (e.key === 'Escape') closeGalleryLightbox();
            }
        });

        async function translateSingleMessage(e, msgId, rawContentEnc) {
            e.stopPropagation();
            const text = decodeURIComponent(rawContentEnc);
            const targetLang = document.getElementById('chat-target-lang').value;
            const popover = document.getElementById('translation-popover');
            const popoverText = document.getElementById('translation-popover-text');
            
            const clientX = e.clientX;
            const clientY = e.clientY;

            popover.style.position = 'fixed';
            const topPos = Math.min(Math.max(20, clientY - 140), window.innerHeight - 230);
            const leftPos = Math.min(Math.max(20, clientX - 150), window.innerWidth - 330);

            popover.style.top = topPos + 'px';
            popover.style.left = leftPos + 'px';
            
            popoverText.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin text-[#008069]"></i> Translating...';
            popover.classList.remove('hidden');

            const fd = new FormData();
            fd.append('text', text);
            fd.append('target_lang', targetLang);

            try {
                const res = await fetch('api/translate.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status && data.translated_text && !data.translated_text.toUpperCase().includes('DISTINCT LANGUAGES')) {
                    popoverText.innerText = data.translated_text;
                } else {
                    popoverText.innerText = text;
                }
            } catch(ex) {
                popoverText.innerText = text;
            }
        }

        function closeTranslationPopover() {
            const popover = document.getElementById('translation-popover');
            if (popover) popover.classList.add('hidden');
        }

        function copyTranslatedText() {
            const txt = document.getElementById('translation-popover-text').innerText;
            navigator.clipboard.writeText(txt);
            alert('Translated text copied to clipboard!');
            closeTranslationPopover();
        }

        async function translateChatMessage() {
            const msgInput = document.getElementById('target-message-input');
            const langSelect = document.getElementById('chat-target-lang');
            const btn = document.getElementById('chat-translate-btn');
            const icon = btn.querySelector('i');
            const text = msgInput.value.trim();

            if (!text) return;

            icon.className = 'fa-solid fa-spinner fa-spin text-sm';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('text', text);
            fd.append('target_lang', langSelect.value);

            try {
                const res = await fetch('api/translate.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status && data.translated_text && !data.translated_text.toUpperCase().includes('DISTINCT LANGUAGES')) {
                    msgInput.value = data.translated_text;
                }
            } catch(e) {
            } finally {
                icon.className = 'fa-solid fa-language text-sm';
                btn.disabled = false;
            }
        }

        document.getElementById('target-message-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (isCurrentChatWubachatUser) {
                    dispatchDirectSend('wubachat');
                } else {
                    handleWhatsAppSendDecision();
                }
            }
        });

        document.getElementById('target-message-input').addEventListener('paste', function(e) {
            const items = (e.clipboardData || e.originalEvent.clipboardData).items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    const blob = items[i].getAsFile();
                    pendingPastedBlob = blob;
                    const reader = new FileReader();
                    reader.onload = function(evt) {
                        document.getElementById('pasted-img-thumb').src = evt.target.result;
                        document.getElementById('pasted-image-preview-bar').classList.remove('hidden');
                    };
                    reader.readAsDataURL(blob);
                }
            }
        });

        function removePastedImage() {
            pendingPastedBlob = null;
            document.getElementById('pasted-image-preview-bar').classList.add('hidden');
        }

        async function triggerCall(type) {
            if (!currentSelectedPhone) { alert('Select a contact to call.'); return; }
            const modal = document.getElementById('calling-modal');
            const title = document.getElementById('call-title-text');
            const videoEl = document.getElementById('local-call-video');
            const audioAv = document.getElementById('audio-call-avatar');

            title.innerText = (type === 'video' ? 'WhatsApp Video Call with +' : 'WhatsApp Voice Call with +') + currentSelectedPhone;
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            try {
                if (type === 'video') {
                    videoEl.classList.remove('hidden');
                    audioAv.classList.add('hidden');
                    activeCallStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
                    videoEl.srcObject = activeCallStream;
                } else {
                    videoEl.classList.add('hidden');
                    audioAv.classList.remove('hidden');
                    audioAv.classList.add('flex');
                    activeCallStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                }
            } catch(ex) {
                alert('Call device access not available.');
                endActiveCall();
            }
        }

        async function startScreenShare() {
            if (!currentSelectedPhone) { alert('Select a contact first.'); return; }
            try {
                const stream = await navigator.mediaDevices.getDisplayMedia({ video: true });
                const modal = document.getElementById('calling-modal');
                document.getElementById('call-title-text').innerText = 'Live Screen Sharing Stream';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                const videoEl = document.getElementById('local-call-video');
                videoEl.classList.remove('hidden');
                document.getElementById('audio-call-avatar').classList.add('hidden');
                videoEl.srcObject = stream;
                activeCallStream = stream;
            } catch(e) {}
        }

        function endActiveCall() {
            if (activeCallStream) {
                activeCallStream.getTracks().forEach(track => track.stop());
                activeCallStream = null;
            }
            const modal = document.getElementById('calling-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function showQuickReaction(e, msgId) {
            e.stopPropagation();
            activeReactionMsgId = msgId;
            const bar = document.getElementById('quick-reaction-bar');
            bar.style.top = (e.clientY - 45) + 'px';
            bar.style.left = (e.clientX - 100) + 'px';
            bar.classList.remove('hidden');
        }

        async function dispatchReaction(emoji) {
            if (!activeReactionMsgId) return;
            document.getElementById('quick-reaction-bar').classList.add('hidden');
            const fd = new FormData();
            fd.append('message_id', activeReactionMsgId);
            fd.append('reaction', emoji);
            await fetch('api/react_message.php', { method: 'POST', body: fd });
            lastMessagesJSON = '';
            if (currentSelectedPhone) loadMessages(currentSelectedPhone, false);
        }

        async function startVoiceRecording() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];
                mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
                mediaRecorder.start();

                document.getElementById('voice-recording-bar').classList.remove('hidden');
                recordSeconds = 0;
                recordTimerInterval = setInterval(() => {
                    recordSeconds++;
                    const m = String(Math.floor(recordSeconds / 60)).padStart(2, '0');
                    const s = String(recordSeconds % 60).padStart(2, '0');
                    document.getElementById('recording-timer').innerText = `${m}:${s}`;
                }, 1000);
            } catch(e) { alert('Microphone access denied.'); }
        }

        function cancelVoiceRecording() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
            clearInterval(recordTimerInterval);
            document.getElementById('voice-recording-bar').classList.add('hidden');
        }

        function stopAndSendVoiceRecording() {
            if (!mediaRecorder) return;
            mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                const replyWamid = document.getElementById('reply-wamid-input').value.trim();
                const channel = isCurrentChatWubachatUser ? 'wubachat' : 'whatsapp';
                const fd = new FormData();
                fd.append('recipient', currentSelectedPhone);
                fd.append('media_type', 'audio');
                fd.append('file', audioBlob, 'voice.webm');
                fd.append('sent_via', channel);
                if (replyWamid) fd.append('reply_to_wamid', replyWamid);
                cancelReplyMode();
                await fetch('api/upload_media.php', { method: 'POST', body: fd });
                lastMessagesJSON = '';
                if (currentSelectedPhone) loadMessages(currentSelectedPhone, true);
            };
            mediaRecorder.stop();
            clearInterval(recordTimerInterval);
            document.getElementById('voice-recording-bar').classList.add('hidden');
        }

        function toggleAttachmentMenu(e) {
            if (e) e.stopPropagation();
            document.getElementById('attachment-menu').classList.toggle('hidden');
        }

        function toggleEmojiPanel(type, e) {
            if (e) e.stopPropagation();
            activeFocusedInput = type;
            const p = document.getElementById('emoji-panel');
            p.classList.toggle('hidden');
            loadEmojiCategory('smileys');
        }

        function loadEmojiCategory(cat) {
            const grid = document.getElementById('emoji-grid-container');
            const arr = emojiCategories[cat] || [];
            grid.innerHTML = arr.map(e => `<button type="button" onclick="insertEmoji('${e}')" class="p-1 hover:bg-gray-100 rounded text-center">${e}</button>`).join('');
        }

        function insertEmoji(e) {
            const target = (activeFocusedInput === 'bulk') ? document.getElementById('bulk_message') : document.getElementById('target-message-input');
            target.value += e;
        }

        let pendingMediaFile = null;
        let pendingMediaType = 'document';

        function uploadDirectMedia(input, type) {
            if (!input.files || input.files.length === 0 || !currentSelectedPhone) return;
            if (typeof toggleAttachmentMenu === 'function') toggleAttachmentMenu();

            pendingMediaFile = input.files[0];
            pendingMediaType = type;

            document.getElementById('media-preview-filename').innerText = pendingMediaFile.name;
            const iconEl = document.getElementById('media-preview-icon');
            
            const ext = pendingMediaFile.name.split('.').pop().toLowerCase();
            if (['xlsx', 'xls', 'csv'].includes(ext)) {
                iconEl.className = 'fa-solid fa-file-excel text-emerald-600';
            } else if (ext === 'pdf') {
                iconEl.className = 'fa-solid fa-file-pdf text-red-500';
            } else {
                iconEl.className = 'fa-solid fa-file-lines text-blue-500';
            }

            document.getElementById('media-preview-bar').classList.remove('hidden');
            document.getElementById('media-preview-bar').classList.add('flex');
            document.getElementById('target-message-input').focus();
            
            input.value = '';
        }

        function cancelPendingMedia() {
            pendingMediaFile = null;
            pendingMediaType = 'document';
            document.getElementById('media-preview-bar').classList.add('hidden');
            document.getElementById('media-preview-bar').classList.remove('flex');
        }

        async function handleSaveContact(e) {
            e.preventDefault();
            const name = document.getElementById('modal-contact-name').value.trim();
            let rawPhone = document.getElementById('modal-contact-phone').value.trim();
            if (!name || !rawPhone) return;

            let cleanPhone = rawPhone.replace(/\D/g, '');
            if (cleanPhone.length === 10) cleanPhone = '91' + cleanPhone;
            else if (cleanPhone.length === 11 && cleanPhone.startsWith('0')) cleanPhone = '91' + cleanPhone.substr(1);
            else if (cleanPhone.length > 12 && cleanPhone.startsWith('91')) cleanPhone = cleanPhone.substr(0, 12);

            // 1. TURANT POPUP BAND & CONFIRMATION (Bina server wait ke 0ms me)
            closeModal();
            showToast('Contact Saved Successfully!');

            // 2. TURANT SIDEBAR LIST UPDATE
            const existingIdx = savedContactsList.findIndex(c => c.phone_number === cleanPhone || c.phone_number.endsWith(cleanPhone.slice(-10)));
            if (existingIdx !== -1) {
                savedContactsList[existingIdx].contact_name = name;
                savedContactsList[existingIdx].is_saved = true;
            } else {
                savedContactsList.unshift({
                    id: Date.now(),
                    contact_name: name,
                    phone_number: cleanPhone,
                    is_saved: true,
                    last_msg: 'No messages yet',
                    last_time: 'Just now',
                    unread_count: 0,
                    is_free_reply_active: false,
                    is_archived: false,
                    is_important: false,
                    raw_last_time: new Date().toISOString()
                });
            }

            // 3. CACHE INITIALIZE & CHAT OPEN
            const new10 = cleanPhone.slice(-10);
            if (!localStorage.getItem('wuba_chat_' + new10)) {
                localStorage.setItem('wuba_chat_' + new10, JSON.stringify([]));
                localStorage.setItem('wuba_chat_' + cleanPhone, JSON.stringify([]));
            }
            renderDefaultContactsList();
            selectChat(cleanPhone, name, true);

            // Form input fields reset
            document.getElementById('modal-contact-name').value = '';
            document.getElementById('modal-contact-phone').value = '';

            // 4. BACKGROUND MEIN SERVER SAVE (Silent non-blocking request)
            const fd = new FormData();
            fd.append('action', 'save_contact');
            fd.append('name', name);
            fd.append('phone', cleanPhone);
            fetch('api/chat_actions.php', { method: 'POST', body: fd }).catch(() => {});
        }
        

        async function handleSaveAutoReply(e) {
            e.preventDefault();
            const enabled = document.getElementById('autoreply-toggle').checked ? 1 : 0;
            const text = document.getElementById('autoreply-text').value.trim();
            const fd = new FormData();
            fd.append('is_enabled', enabled);
            fd.append('reply_message', text);
            await fetch('api/save_autoreply.php', { method: 'POST', body: fd });
            closeAutoReplyModal();
            showToast('Auto-reply settings updated successfully!');
        }

        async function handleRechargeSubmit(e) {
            e.preventDefault();
            const amountInput = document.getElementById('recharge-amount');
            const amount = parseFloat(amountInput.value);
            
            if (isNaN(amount) || amount < 100) {
                alert('Minimum recharge amount is ₹100.00. Please enter ₹100 or more.');
                amountInput.value = 100;
                validateAndSyncQR(100);
                return;
            }

            const utr = document.getElementById('recharge-utr').value.trim();
            const fd = new FormData();
            fd.append('amount', amount);
            fd.append('utr_number', utr);
            
            try {
                const res = await fetch('api/recharge_wallet.php', { method: 'POST', body: fd });
                const rawText = await res.text();
                
                let data;
                try {
                    data = JSON.parse(rawText);
                } catch (parseErr) {
                    console.error("Server raw output:", rawText);
                    alert("Server returned invalid response. Check console for details.");
                    return;
                }

                alert(data.message || 'Recharge request submitted successfully!');
                if (data.status) {
                    closeRechargeModal();
                    location.reload();
                }
            } catch (err) {
                alert('Network error while processing recharge: ' + err.message);
            }
        }

        async function handleImportContactsSubmit(e) {
            e.preventDefault();
            const fileInput = document.getElementById('contacts-csv-file');
            if (!fileInput.files || !fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('contacts_file', fileInput.files[0]);
            const res = await fetch('api/import_contacts.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message || 'Contacts imported successfully!');
            closeImportContactsModal();
            location.reload();
        }

        async function handleImportBackupSubmit(e) {
            e.preventDefault();
            const targetPhone = document.getElementById('backup-target-phone').value.trim();
            const fileInput = document.getElementById('backup-chat-file');
            if (!fileInput.files || !fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('target_phone', targetPhone.replace(/\D/g, ''));
            fd.append('backup_file', fileInput.files[0]);
            const res = await fetch('api/import_backup.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message || 'Chat history restored successfully!');
            closeImportBackupModal();
            location.reload();
        }

        async function handleMetaSendOTP(e) {
            e.preventDefault();
            const phone = document.getElementById('meta-connect-phone').value.trim();
            if (!phone) {
                alert('Please enter a valid mobile number.');
                return;
            }

            const sendBtn = document.getElementById('meta-send-btn');
            const originalText = sendBtn.innerHTML;
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Connecting to Meta...';

            const fd = new FormData();
            fd.append('phone', phone);
            fd.append('action', 'send_otp');

            try {
                const res = await fetch('api/meta_connect.php', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.status) {
                    if (data.direct) {
                        alert(data.message || 'WhatsApp Connected Successfully!');
                        location.reload();
                    } else {
                        document.getElementById('meta-send-form').classList.add('hidden');
                        document.getElementById('meta-verify-form').classList.remove('hidden');
                    }
                } else {
                    alert(data.message || 'Error connecting to Meta.');
                }
            } catch (err) {
                alert('Network or server error while connecting to Meta API.');
            } finally {
                sendBtn.disabled = false;
                sendBtn.innerHTML = originalText;
            }
        }

        async function handleMetaVerifyOTP(e) {
            e.preventDefault();
            const otp = document.getElementById('meta-otp-input').value.trim();
            if (!otp) {
                alert('Please enter the verification code.');
                return;
            }

            const verifyBtn = document.getElementById('meta-verify-btn');
            const originalText = verifyBtn.innerHTML;
            verifyBtn.disabled = true;
            verifyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Verifying...';

            const fd = new FormData();
            fd.append('otp', otp);
            fd.append('action', 'verify_otp');

            try {
                const res = await fetch('api/meta_connect.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.status) {
                    alert(data.message || 'Meta Connected Successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Invalid verification code.');
                }
            } catch (err) {
                alert('Network error during verification.');
            } finally {
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = originalText;
            }
        }

        async function handleMetaDisconnect() {
            if (!confirm('Disconnect Meta Cloud API integration?')) return;
            await fetch('api/meta_connect.php?action=disconnect', { method: 'POST' });
            location.reload();
        }

        function openCameraCapture() {
            const modal = document.getElementById('camera-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
                cameraStream = stream;
                document.getElementById('camera-video-feed').srcObject = stream;
            }).catch(() => { alert('Camera access denied.'); closeCameraModal(); });
        }

        function closeCameraModal() {
            if (cameraStream) cameraStream.getTracks().forEach(t => t.stop());
            const modal = document.getElementById('camera-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function snapAndSendCameraPhoto() {
            const video = document.getElementById('camera-video-feed');
            const canvas = document.getElementById('camera-canvas');
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;
            canvas.getContext('2d').drawImage(video, 0, 0);
            canvas.toBlob(async blob => {
                closeCameraModal();
                if (!currentSelectedPhone) { alert('Select a chat first.'); return; }
                const replyWamid = document.getElementById('reply-wamid-input').value.trim();
                const channel = isCurrentChatWubachatUser ? 'wubachat' : 'whatsapp';
                const fd = new FormData();
                fd.append('recipient', currentSelectedPhone);
                fd.append('media_type', 'image');
                fd.append('file', blob, 'camera_snap.jpg');
                fd.append('sent_via', channel);
                if (replyWamid) fd.append('reply_to_wamid', replyWamid);
                cancelReplyMode();
                await fetch('api/upload_media.php', { method: 'POST', body: fd });
                lastMessagesJSON = '';
                loadMessages(currentSelectedPhone, true);
            }, 'image/jpeg');
        }

        function shareContactCard() {
            const name = prompt('Contact Name:');
            const num = prompt('Contact Phone Number:');
            if (name && num && currentSelectedPhone) {
                const replyWamid = document.getElementById('reply-wamid-input').value.trim();
                const channel = isCurrentChatWubachatUser ? 'wubachat' : 'whatsapp';
                const fd = new FormData();
                fd.append('recipient', currentSelectedPhone);
                fd.append('message', `📇 [Contact Card]\nName: ${name}\nPhone: ${num}`);
                fd.append('sent_via', channel);
                if (replyWamid) fd.append('reply_to_wamid', replyWamid);
                cancelReplyMode();
                fetch('api/send_message.php', { method: 'POST', body: fd }).then(() => {
                    lastMessagesJSON = '';
                    loadMessages(currentSelectedPhone, true);
                });
            }
        }

        function createPoll() {
            const question = prompt('Poll Question:');
            const opt1 = prompt('Option 1:');
            const opt2 = prompt('Option 2:');
            if (question && opt1 && opt2 && currentSelectedPhone) {
                const replyWamid = document.getElementById('reply-wamid-input').value.trim();
                const channel = isCurrentChatWubachatUser ? 'wubachat' : 'whatsapp';
                const fd = new FormData();
                fd.append('recipient', currentSelectedPhone);
                fd.append('message', `📊 [Poll]\n${question}\n1. ${opt1}\n2. ${opt2}`);
                fd.append('sent_via', channel);
                if (replyWamid) fd.append('reply_to_wamid', replyWamid);
                cancelReplyMode();
                fetch('api/send_message.php', { method: 'POST', body: fd }).then(() => {
                    lastMessagesJSON = '';
                    loadMessages(currentSelectedPhone, true);
                });
            }
        }

        function toggleInChatSearch() {
            const b = document.getElementById('in-chat-search-bar');
            b.classList.toggle('hidden');
            if (!b.classList.contains('hidden')) document.getElementById('chat-search-input').focus();
        }

        function toggleOptionsMenu(e) {
            if (e) e.stopPropagation();
            document.getElementById('options-dropdown').classList.toggle('hidden');
        }

        async function clearCurrentChat() {
            if (!confirm('Clear all messages for this chat?')) return;
            const fd = new FormData();
            fd.append('action', 'clear_chat');
            fd.append('phone', currentSelectedPhone);
            await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            toggleOptionsMenu();
            delete chatCache[currentSelectedPhone];
            lastMessagesJSON = '';
            loadMessages(currentSelectedPhone, false);
        }

        async function deleteCurrentChat() {
            if (!confirm('Delete entire conversation?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_conversation');
            fd.append('phone', currentSelectedPhone);
            await fetch('api/chat_actions.php', { method: 'POST', body: fd });
            location.reload();
        }
        async function triggerSoftRefresh() {
            const icon = document.getElementById('manual-refresh-icon');
            const btn = document.getElementById('manual-refresh-btn');
            const label = btn ? btn.querySelector('span') : null;

            if (icon) icon.classList.add('fa-spin');
            if (btn) btn.disabled = true;
            if (label) label.innerText = 'Checking...';

            try {
                // 1. Contacts metadata sync
                try {
                    const resC = await fetch('api/chat_actions.php?action=get_contacts_sync');
                    const dC = await resC.json();
                    if (dC.status && dC.contacts) {
                        savedContactsList = dC.contacts.map(newC => {
                            const oldC = savedContactsList.find(o => o.phone_number === newC.phone_number || o.phone_number.endsWith(newC.phone_number.slice(-10)));
                            return {
                                ...newC,
                                server_max_id: Number(newC.server_max_id || (oldC ? (oldC.server_max_id || oldC.max_id || 0) : 0))
                            };
                        });
                    }
                } catch(eContacts) {}

                // 2. Sirf Not Synced wali chats filter karein
                const pendingChats = savedContactsList.filter(c => !isChatSynced(c));
                let newMessagesFetched = 0;

                // Agar sabhi chats pehle se Synced hain:
                if (pendingChats.length === 0) {
                    renderDefaultContactsList();
                    showToast(`✓ All ${savedContactsList.length} chats are fully Synced`);
                    return;
                }

                // 3. Ek-ek karke line se (Queue mein) chats sync karein taaki server par load na pade
                let currentIdx = 0;
                for (const chat of pendingChats) {
                    currentIdx++;
                    if (label) label.innerText = `Syncing ${currentIdx}/${pendingChats.length}...`;

                    try {
                        const cleanPhone = chat.phone_number.replace(/\D/g, '');
                        const lastLocalId = getLocalMaxId(cleanPhone);
                        
                        const url = `api/fetch_messages.php?phone=${encodeURIComponent(cleanPhone)}&after_id=${lastLocalId}`;
                        const res = await fetch(url);
                        const data = await res.json();

                        if (data.status && data.messages && data.messages.length > 0) {
                            newMessagesFetched += data.messages.length;
                            
                            const k10 = cleanPhone.slice(-10);
                            let localList = [];
                            try {
                                const raw = localStorage.getItem('wuba_chat_' + k10) || localStorage.getItem('wuba_chat_' + cleanPhone);
                                if (raw) localList = JSON.parse(raw) || [];
                            } catch(e) {}

                            const msgMap = new Map();
                            localList.forEach(m => msgMap.set(String(m.id), m));
                            data.messages.forEach(m => msgMap.set(String(m.id), m));

                            const merged = Array.from(msgMap.values()).sort((a, b) => {
                                let tA = a.created_at ? new Date(a.created_at.replace(' ', 'T')).getTime() : 0;
                                let tB = b.created_at ? new Date(b.created_at.replace(' ', 'T')).getTime() : 0;
                                return tA - tB;
                            });

                            localStorage.setItem('wuba_chat_' + k10, JSON.stringify(merged));
                            localStorage.setItem('wuba_chat_' + cleanPhone, JSON.stringify(merged));

                            // Sirf max ID update karein (Unread count touch nahi hoga, auto-read bilkul band)
                            chat.server_max_id = Number(data.messages[data.messages.length - 1].id);
                        }
                    } catch(errSingle) {
                        // Agar kisi ek number par issue ho toh agle number ka sync rukega nahi
                    }
                }

                // 4. Sab complete hone par list render karein
                renderDefaultContactsList();

                // Agar samne open chat mein koi naya message aaya ho toh use update karein
                if (currentSelectedPhone) {
                    const k10 = currentSelectedPhone.slice(-10);
                    const activeSaved = localStorage.getItem('wuba_chat_' + k10) || localStorage.getItem('wuba_chat_' + currentSelectedPhone);
                    if (activeSaved) {
                        try { renderMessagesStream(JSON.parse(activeSaved), false); } catch(e) {}
                    }
                }

                showToast(`✓ Synced: ${newMessagesFetched} new messages in ${pendingChats.length} chats`);

            } catch (err) {
                console.error('Selective sync error:', err);
                showToast('Sync completed');
            } finally {
                setTimeout(() => {
                    if (icon) icon.classList.remove('fa-spin');
                    if (btn) btn.disabled = false;
                    if (label) label.innerText = 'Refresh';
                }, 300);
            }
        }
        function switchTab(tab) {
            const chatSec = document.getElementById('chat-section');
            const bulkSec = document.getElementById('bulk-section');
            const chatBtn = document.getElementById('tab-chat-btn');
            const bulkBtn = document.getElementById('tab-bulk-btn');

            if (tab === 'chat') {
                chatSec.classList.remove('hidden'); 
                chatSec.classList.add('flex'); 
                bulkSec.classList.add('hidden');
                chatBtn.className = "px-3 py-1 bg-white text-[#009b67] shadow-sm rounded-lg font-bold transition flex items-center space-x-1";
                bulkBtn.className = "px-3 py-1 text-gray-600 hover:text-gray-900 rounded-lg font-bold transition flex items-center space-x-1";
            } else {
                bulkSec.classList.remove('hidden'); 
                chatSec.classList.add('hidden'); 
                chatSec.classList.remove('flex'); 
                bulkBtn.className = "px-3 py-1 bg-white text-[#009b67] shadow-sm rounded-lg font-bold transition flex items-center space-x-1";
                chatBtn.className = "px-3 py-1 text-gray-600 hover:text-gray-900 rounded-lg font-bold transition flex items-center space-x-1";
            }
        }

        renderDefaultContactsList();

        window.addEventListener('DOMContentLoaded', () => {
    const savedPhone = localStorage.getItem('wuba_active_phone');
    const savedName = localStorage.getItem('wuba_active_name');
    const savedSaved = localStorage.getItem('wuba_active_saved') === 'true';

    if (savedPhone) {
        // UI pehle local cache se instant dikhao (0ms delay)
        const last10 = savedPhone.slice(-10);
        const cached = localStorage.getItem('wuba_chat_' + last10) || localStorage.getItem('wuba_chat_' + savedPhone);
        if (cached) {
            try {
                currentSelectedPhone = savedPhone;
                document.getElementById('target-phone-input').value = savedPhone;
                document.getElementById('active-chat-name').innerText = savedName || '+' + savedPhone;
                renderMessagesStream(JSON.parse(cached), true);
            } catch(e) {}
        }
        
        // Server API hit ko 600ms stagger karein taaki Hostinger 403/504 trigger na kare
        setTimeout(() => {
            selectChat(savedPhone, savedName, savedSaved);
        }, 600);
    }
});
    </script>
</body>
</html>