<?php
// live-test.php - Real Database Connected Wubachat Auto-Sync Test Console
error_reporting(E_ALL);
log_errors: ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) {
    // Agar login nahi hai toh dummy user id ya exit
    $user_id = 2; // Aapke account ki verified ID
} else {
    $user_id = (int)$_SESSION['user_id'];
}

require_once __DIR__ . '/config.php';

// Format time to IST safely
function formatTimeISTLive($datetimeStr) {
    if (empty($datetimeStr)) return '';
    try {
        $dt = new DateTime($datetimeStr);$dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('H:i');
    } catch (Exception $e) {
        return substr($datetimeStr, 11, 5);
    }
}

if (isset($_GET['action']) &&$_GET['action'] == 'get_contacts_sync') {
    header('Content-Type: application/json');
    try {
        $contacts_map = [];
        $seen = [];$totalUnread = 0;

        // 1. Saved contacts with latest message subqueries
        $stmt_c =$pdo->prepare("
            SELECT c.id, COALESCE(c.contact_name, c.name, '') AS contact_name, c.phone_number, c.is_archived, c.is_important, c.avatar_url,
                   (SELECT message_text FROM messages WHERE user_id = ? AND (recipient_phone = c.phone_number OR RIGHT(recipient_phone, 10) = RIGHT(c.phone_number, 10)) ORDER BY id DESC LIMIT 1) AS last_msg,
                   (SELECT created_at FROM messages WHERE user_id = ? AND (recipient_phone = c.phone_number OR RIGHT(recipient_phone, 10) = RIGHT(c.phone_number, 10)) ORDER BY id DESC LIMIT 1) AS last_msg_time,
                   (SELECT COUNT(*) FROM messages WHERE user_id = ? AND (recipient_phone = c.phone_number OR RIGHT(recipient_phone, 10) = RIGHT(c.phone_number, 10)) AND status = 'unread' AND direction = 'inbound') AS unread_count
            FROM contacts c 
            WHERE c.user_id = ? 
            ORDER BY COALESCE(last_msg_time, c.created_at) DESC
        ");
        $stmt_c->execute([$user_id,$user_id, $user_id,$user_id]);
        $saved_rows =$stmt_c->fetchAll();

        foreach ($saved_rows as$r) {
            $c_phone = preg_replace('/[^0-9]/', '',$r['phone_number']);
            $k10 = strlen($c_phone) >= 10 ? substr($c_phone, -10) :$c_phone;
            if (!empty($k10) && !isset($seen[$k10])) {$raw_name = trim($r['contact_name']);$is_generic = empty($raw_name) \vert{}\vert{} preg_match('/^User\s+\d+$/i', $raw_name) \vert{}\vert{} strpos($raw_name, '+') === 0;
                $u_count = (int)$r['unread_count'];
                $totalUnread +=$u_count;

                $contacts_map[] = [
                    'id'           => (int)$r['id'],
                    'contact_name' => $is_generic ? '+' . $c_phone :$raw_name,
                    'phone_number' => $c_phone,
                    'last_msg'     => $r['last_msg'] ?: 'No messages yet',
                    'last_time'    => !empty($r['last_msg_time']) ? formatTimeISTLive($r['last_msg_time']) : '',
                    'unread_count' => $u_count,
                    'raw_last_time'=> $r['last_msg_time'] ?? $r['created_at']
                ];
                $seen[$k10] = true;
            }
        }

        // 2. Direct message senders not in contacts table
        $stmt_m =$pdo->prepare("
            SELECT recipient_phone, message_text AS last_msg, created_at AS last_time,
                   (SELECT COUNT(*) FROM messages WHERE user_id = ? AND (recipient_phone = m.recipient_phone OR RIGHT(recipient_phone, 10) = RIGHT(m.recipient_phone, 10)) AND status = 'unread' AND direction = 'inbound') AS unread_count
            FROM messages m
            WHERE user_id = ? AND id IN (
                SELECT MAX(id) FROM messages 
                WHERE user_id = ? AND recipient_phone IS NOT NULL AND recipient_phone != '' 
                GROUP BY RIGHT(recipient_phone, 10)
            )
            ORDER BY id DESC
        ");
        $stmt_m->execute([$user_id, $user_id,$user_id]);
        $msg_rows =$stmt_m->fetchAll();

        foreach ($msg_rows as$mr) {
            $m_phone = preg_replace('/[^0-9]/', '',$mr['recipient_phone']);
            $k10 = strlen($m_phone) >= 10 ? substr($m_phone, -10) :$m_phone;
            if (!empty($k10) && !isset($seen[$k10])) {
                $u_count = (int)$mr['unread_count'];
                $totalUnread +=$u_count;

                $contacts_map[] = [
                    'id'           => count($contacts_map) + 1,                     'contact_name' => '+' .$m_phone,
                    'phone_number' => $m_phone,
                    'last_msg'     => $mr['last_msg'] ?: 'Tap to open chat',
                    'last_time'    => !empty($mr['last_time']) ? formatTimeISTLive($mr['last_time']) : '',
                    'unread_count' => $u_count,
                    'raw_last_time'=> $mr['last_time']
                ];
                $seen[$k10] = true;
            }
        }

        // Sort strictly by latest message timestamp DESC
        usort($contacts_map, function($a, $b) {$tA = !empty($a['raw_last_time']) ? strtotime($a['raw_last_time']) : 0;
            $tB = !empty($b['raw_last_time']) ? strtotime($b['raw_last_time']) : 0;
            if ($tA ===$tB) return 0;
            return ($tA >$tB) ? -1 : 1;
        });

        echo json_encode(['status' => true, 'contacts' => $contacts_map, 'total_unread' =>$totalUnread]);
    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <title>Wubachat Live Auto-Sync Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 p-6 flex justify-center items-center h-screen">

    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 space-y-4 border border-gray-200">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="font-bold text-gray-800 text-base"><i class="fa-solid fa-bolt text-emerald-500 mr-2"></i> Live Contacts & Chat Sync</h3>
            <span id="sync-status" class="text-xs bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full font-bold">Syncing...</span>
        </div>

        <div id="contacts-stream" class="space-y-2 max-h-96 overflow-y-auto divide-y divide-gray-100">
            <p class="text-xs text-gray-400 text-center py-4">Loading contacts list...</p>
        </div>
    </div>

    <script>
        let cachedSignature = '';

        function fetchLiveContactsSync() {
            fetch('live-test.php?action=get_contacts_sync&_t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    if (data.status && data.contacts) {
                        const newSig = JSON.stringify(data.contacts.map(c => [c.phone_number, c.last_msg, c.last_time, c.unread_count]));
                        
                        if (newSig !== cachedSignature) {
                            cachedSignature = newSig;
                            renderContactsList(data.contacts);
                            document.getElementById('sync-status').innerText = 'Updated ' + new Date().toLocaleTimeString();
                        }
                    }
                })
                .catch(err => console.error('Sync Error:', err));
        }

        function renderContactsList(contacts) {
            const container = document.getElementById('contacts-stream');
            if (!contacts || contacts.length === 0) {
                container.innerHTML = '<p class="text-xs text-gray-400 text-center py-4">No conversations found.</p>';
                return;
            }

            container.innerHTML = contacts.map(c => `
                <div class="p-3 hover:bg-gray-50 rounded-xl flex items-center justify-between transition cursor-pointer">
                    <div class="flex items-center space-x-3 truncate">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 text-[#00a884] font-bold flex items-center justify-center text-xs shrink-0">
                            ${c.contact_name.substr(0, 2).toUpperCase()}
                        </div>
                        <div class="truncate">
                            <h4 class="text-xs font-bold text-gray-900 truncate">${escapeHtml(c.contact_name)}</h4>
                            <p class="text-[11px] text-gray-500 truncate max-w-[180px]">${escapeHtml(c.last_msg)}</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="text-[10px] text-gray-400 block">${c.last_time || ''}</span>
                        ${Number(c.unread_count) > 0 ? `<span class="bg-[#25d366] text-white text-[10px] font-extrabold rounded-full px-1.5 py-0.2 mt-1 inline-block">${c.unread_count}</span>` : ''}
                    </div>
                </div>
            `).join('');
        }

        function escapeHtml(text) {
            return String(text || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        }

        // Run sync every 1.5 seconds
        setInterval(fetchLiveContactsSync, 1500);
        window.addEventListener('DOMContentLoaded', fetchLiveContactsSync);
    </script>

</body>
</html>