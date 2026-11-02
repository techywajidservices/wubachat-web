<?php
// api/chat_actions.php - Universal Contact, Profile & Chat Management Actions (High Performance Optimized)
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/../config.php';

$user_id = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? ($_GET['action'] ?? '');

try {
    function formatTimeISTAction($datetimeStr) {
        if (empty($datetimeStr)) return '';
        try {
            $cleanStr = trim($datetimeStr);
            if (strpos($cleanStr, ' ') !== false && strpos($cleanStr, 'T') === false) {
                $cleanStr = str_replace(' ', 'T', $cleanStr);
            }
            if (substr($cleanStr, -1) !== 'Z' && strpos($cleanStr, '+') === false && strpos($cleanStr, '-', 10) === false) {
                $cleanStr .= 'Z';
            }
            $dt = new DateTime($cleanStr);
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
            return $dt->format('H:i');
        } catch (Exception $e) {
            return substr($datetimeStr, 11, 5);
        }
    }

    function ensureContactRow($pdo, $user_id, $phone) {
        $last10 = substr($phone, -10);
        $stmt = $pdo->prepare("SELECT id FROM contacts WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?) LIMIT 1");
        $stmt->execute([$user_id, $phone, "%{$last10}"]);
        $row = $stmt->fetch();
        if (!$row) {
            $stmtIns = $pdo->prepare("INSERT INTO contacts (user_id, contact_name, phone_number) VALUES (?, ?, ?)");
            $stmtIns->execute([$user_id, '+' . $phone, $phone]);
        }
    }

    // Delete Single Message (Strictly isolated by user_id)
    if ($action === 'delete_message_option' || $action === 'delete_message') {
        $msg_id      = (int)($_POST['message_id'] ?? 0);
        $delete_type = strtolower(trim($_POST['delete_type'] ?? 'me'));

        if ($msg_id <= 0) {
            echo json_encode(['status' => false, 'message' => 'Invalid Message ID']);
            exit;
        }

        if ($delete_type === 'everyone') {
            $stmt = $pdo->prepare("UPDATE messages SET message_text = '🚫 This message was deleted', message_body = '🚫 This message was deleted', media_url = NULL WHERE id = ? AND user_id = ?");
            $stmt->execute([$msg_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND user_id = ?");
            $stmt->execute([$msg_id, $user_id]);
        }

        echo json_encode(['status' => true, 'message' => 'Message deleted successfully.']);
        exit;
    }

    // Clear Chat History
    if ($action === 'clear_chat') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        if (!empty($phone)) {
            $last10 = substr($phone, -10);
            $pdo->prepare("DELETE FROM messages WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone LIKE ?)")
                ->execute([$user_id, $phone, "%{$last10}"]);
            echo json_encode(['status' => true, 'message' => 'Chat history cleared.']);
            exit;
        }
    }

    // Delete Entire Conversation
    if ($action === 'delete_conversation') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        if (!empty($phone)) {
            $last10 = substr($phone, -10);
            $pdo->prepare("DELETE FROM messages WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone LIKE ?)")
                ->execute([$user_id, $phone, "%{$last10}"]);
            $pdo->prepare("DELETE FROM contacts WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?)")
                ->execute([$user_id, $phone, "%{$last10}"]);
            echo json_encode(['status' => true, 'message' => 'Conversation deleted successfully.']);
            exit;
        }
    }

    // Profile Update
    if ($action === 'update_profile') {
        $name       = trim($_POST['name'] ?? '');
        $bio        = trim($_POST['bio'] ?? '');
        $avatar_url = trim($_POST['avatar_url'] ?? '');
        $old_pass   = $_POST['old_password'] ?? '';
        $new_pass   = $_POST['new_password'] ?? '';

        $updates = [];
        $params  = [];

        if (!empty($name)) { $updates[] = "name = ?"; $params[] = $name; }
        if (!empty($bio))  { $updates[] = "bio = ?";  $params[] = $bio; }
        if (!empty($avatar_url)) { $updates[] = "avatar_url = ?"; $params[] = $avatar_url; }

        if (!empty($new_pass)) {
            $stmtUser = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmtUser->execute([$user_id]);
            $userData = $stmtUser->fetch();

            if ($userData && isset($userData['password']) && !empty($userData['password'])) {
                if (empty($old_pass) || !password_verify($old_pass, $userData['password'])) {
                    echo json_encode(['status' => false, 'message' => 'Current password is incorrect.']);
                    exit;
                }
            }
            $hashedPassword = password_hash($new_pass, PASSWORD_DEFAULT);
            $updates[] = "password = ?";
            $params[] = $hashedPassword;
        }

        if (!empty($updates)) {
            $params[] = $user_id;
            try {
                $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            } catch (Exception $ex) {
                $pdo->prepare("UPDATE users SET full_name = ?, bio = ?, avatar_url = ? WHERE id = ?")->execute([$name, $bio, $avatar_url, $user_id]);
            }
        }

        echo json_encode(['status' => true, 'message' => 'Profile updated successfully!']);
        exit;
    }

    // Get Contact Info for Drawer
    if ($action === 'get_contact_info') {
        $phone = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? ($_POST['phone'] ?? ''));
        if (empty($phone)) {
            echo json_encode(['status' => false, 'message' => 'Phone number required']);
            exit;
        }

        $last10 = substr($phone, -10);
        $stmt = $pdo->prepare("
            SELECT id, COALESCE(contact_name, name, '') AS contact_name, phone_number, avatar_url, bio 
            FROM contacts 
            WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?) 
            LIMIT 1
        ");
        $stmt->execute([$user_id, $phone, "%{$last10}"]);
        $contact = $stmt->fetch();

        $c_name = $contact['contact_name'] ?? ('+' . $phone);
        if (empty($c_name) || preg_match('/^User\s+\d+$/i', $c_name)) {
            $c_name = '+' . $phone;
        }

        // Fetch shared media for this contact
        $stmt_media = $pdo->prepare("
            SELECT message_text AS url, created_at 
            FROM messages 
            WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone LIKE ?) 
              AND (message_text LIKE '[Image]:%' OR message_text LIKE '[Document]:%') 
            ORDER BY id DESC LIMIT 15
        ");
        $stmt_media->execute([$user_id, $phone, "%{$last10}"]);
        $media_rows = $stmt_media->fetchAll();
        $media_list = [];
        foreach ($media_rows as $m_row) {
            $clean_url = trim(str_replace(['[Image]:', '[Document]:'], '', $m_row['url']));
            if (!empty($clean_url)) {
                $media_list[] = ['url' => $clean_url, 'created_at' => $m_row['created_at']];
            }
        }

        // Check if blocked
        $stmt_block = $pdo->prepare("SELECT id FROM blocked_contacts WHERE user_id = ? AND phone_number = ? LIMIT 1");
        $stmt_block->execute([$user_id, $phone]);
        $is_blocked = (bool)$stmt_block->fetch();

        echo json_encode([
            'status'       => true,
            'contact_id'   => $contact['id'] ?? 0,
            'contact_name' => $c_name,
            'phone'        => $contact['phone_number'] ?? $phone,
            'avatar'       => $contact['avatar_url'] ?? '',
            'bio'          => (!empty($contact['bio']) ? $contact['bio'] : 'Hey there! I am using WhatsApp.'),
            'is_blocked'   => $is_blocked,
            'media'        => $media_list
        ]);
        exit;
    }

    // Get Blocked List
    if ($action === 'get_blocked_list') {
        $stmt = $pdo->prepare("SELECT phone_number, created_at FROM blocked_contacts WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
        echo json_encode(['status' => true, 'records' => $stmt->fetchAll()]);
        exit;
    }

    // Block Contact
    if ($action === 'block_contact') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        if (!empty($phone)) {
            $pdo->prepare("INSERT IGNORE INTO blocked_contacts (user_id, phone_number) VALUES (?, ?)")->execute([$user_id, $phone]);
            echo json_encode(['status' => true, 'message' => 'Contact blocked successfully.']);
            exit;
        }
    }

    // Unblock Contact
    if ($action === 'unblock_contact') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        if (!empty($phone)) {
            $pdo->prepare("DELETE FROM blocked_contacts WHERE user_id = ? AND phone_number = ?")->execute([$user_id, $phone]);
            echo json_encode(['status' => true, 'message' => 'Contact unblocked successfully.']);
            exit;
        }
    }

    // Mark as Read / Unread
    if ($action === 'mark_read' || $action === 'mark_unread') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $statusVal = ($action === 'mark_read') ? 'read' : 'unread';
        if (!empty($phone)) {
            $last10 = substr($phone, -10);
            $pdo->prepare("UPDATE messages SET status = ? WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone LIKE ?) AND direction = 'inbound'")
                ->execute([$statusVal, $user_id, $phone, "%{$last10}"]);
        }
        echo json_encode(['status' => true, 'message' => 'Status updated']);
        exit;
    }

    // Archive / Unarchive
    if ($action === 'archive_chat' || $action === 'unarchive_chat') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $archiveVal = ($action === 'archive_chat') ? 1 : 0;
        if (!empty($phone)) {
            ensureContactRow($pdo, $user_id, $phone);
            $last10 = substr($phone, -10);
            $pdo->prepare("UPDATE contacts SET is_archived = ? WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?)")
                ->execute([$archiveVal, $user_id, $phone, "%{$last10}"]);
        }
        echo json_encode(['status' => true, 'is_archived' => $archiveVal, 'message' => 'Chat archive status updated.']);
        exit;
    }

    // Important Toggle
    if ($action === 'toggle_important_chat') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        if (!empty($phone)) {
            ensureContactRow($pdo, $user_id, $phone);
            $last10 = substr($phone, -10);
            $pdo->prepare("UPDATE contacts SET is_important = IF(is_important = 1, 0, 1) WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?)")
                ->execute([$user_id, $phone, "%{$last10}"]);
            
            $stmtChk = $pdo->prepare("SELECT is_important FROM contacts WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?) LIMIT 1");
            $stmtChk->execute([$user_id, $phone, "%{$last10}"]);
            $newImp = (int)($stmtChk->fetchColumn() ?? 0);

            echo json_encode(['status' => true, 'is_important' => $newImp, 'message' => 'Toggled important.']);
            exit;
        }
    }

    // =========================================================================
    // Background Sync - High-Performance Index-Optimized Engine (< 0.2s)
    // =========================================================================
    if ($action === 'get_contacts_sync') {
        $contacts_map = [];
        $seen = [];
        $totalUnread = 0;

        // 1. Unread counts fetch karein
        $unread_counts_map = [];
        try {
            $stmt_u = $pdo->prepare("
                SELECT recipient_phone, COUNT(*) as cnt 
                FROM messages 
                WHERE user_id = ? AND status = 'unread' AND direction = 'inbound' 
                GROUP BY recipient_phone
            ");
            $stmt_u->execute([$user_id]);
            $u_rows = $stmt_u->fetchAll();
            foreach ($u_rows as $ur) {
                $p = preg_replace('/[^0-9]/', '', $ur['recipient_phone']);
                $k = strlen($p) >= 10 ? substr($p, -10) : $p;
                if (!empty($k)) {
                    $cnt = (int)$ur['cnt'];
                    $unread_counts_map[$k] = ($unread_counts_map[$k] ?? 0) + $cnt;
                    $totalUnread += $cnt;
                }
            }
        } catch (Exception $e) {}

        // 2. 24-hr Free Inbound sessions fetch karein
        $free_window_map = [];
        try {
            $stmt_free = $pdo->prepare("
                SELECT recipient_phone 
                FROM messages 
                WHERE user_id = ? AND direction = 'inbound' AND created_at >= NOW() - INTERVAL 24 HOUR 
                GROUP BY recipient_phone
            ");
            $stmt_free->execute([$user_id]);
            $f_rows = $stmt_free->fetchAll();
            foreach ($f_rows as $fr) {
                $p = preg_replace('/[^0-9]/', '', $fr['recipient_phone']);
                $k = strlen($p) >= 10 ? substr($p, -10) : $p;
                if (!empty($k)) {
                    $free_window_map[$k] = true;
                }
            }
        } catch (Exception $e) {}

        // Prepared statements
        $stmt_msg_lookup = $pdo->prepare("
            SELECT id, message_text, created_at 
            FROM messages 
            WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone LIKE ?) 
            ORDER BY id DESC LIMIT 1
        ");

        // 3. Saved Contacts Fetch
        $stmt_c = $pdo->prepare("
            SELECT id, COALESCE(contact_name, name, '') AS contact_name, phone_number, is_archived, is_important, avatar_url 
            FROM contacts 
            WHERE user_id = ? 
            ORDER BY id DESC
        ");
        $stmt_c->execute([$user_id]);
        $saved_rows = $stmt_c->fetchAll();

        foreach ($saved_rows as $r) {
            $c_phone = preg_replace('/[^0-9]/', '', $r['phone_number']);
            $k10 = strlen($c_phone) >= 10 ? substr($c_phone, -10) : $c_phone;
            if (!empty($k10) && !isset($seen[$k10])) {
                $raw_name = trim($r['contact_name']);
                $is_generic = empty($raw_name) || preg_match('/^User\s+\d+$/i', $raw_name) || strpos($raw_name, '+') === 0;

                // Fast indexed lookup for latest message
                $stmt_msg_lookup->execute([$user_id, $c_phone, "%{$k10}"]);
                $last_msg_row = $stmt_msg_lookup->fetch();

                $u_count = $unread_counts_map[$k10] ?? 0;
                $is_free = isset($free_window_map[$k10]);

                $contacts_map[] = [
                    'id'                   => (int)$r['id'],
                    'contact_name'         => $is_generic ? '+' . $c_phone : $raw_name,
                    'phone_number'         => $c_phone,
                    'is_saved'             => !$is_generic,
                    'last_msg'             => $last_msg_row['message_text'] ?? 'No messages yet',
                    'last_time'            => !empty($last_msg_row['created_at']) ? formatTimeISTAction($last_msg_row['created_at']) : '',
                    'unread_count'         => $u_count,
                    'is_free_reply_active' => $is_free,
                    'is_archived'          => (int)($r['is_archived'] ?? 0) === 1,
                    'is_important'         => (int)($r['is_important'] ?? 0) === 1,
                    'raw_last_time'        => $last_msg_row['created_at'] ?? '',
                    'max_id'               => (int)($last_msg_row['id'] ?? 0)
                ];
                $seen[$k10] = true;
            }
        }

        // 4. Direct Messages se Unsaved Contacts Fetch
        $stmt_m = $pdo->prepare("
            SELECT id, recipient_phone, message_text, created_at 
            FROM messages 
            WHERE user_id = ? AND recipient_phone IS NOT NULL AND recipient_phone != '' 
            ORDER BY id DESC LIMIT 300
        ");
        $stmt_m->execute([$user_id]);
        $recent_messages = $stmt_m->fetchAll();

        foreach ($recent_messages as $mr) {
            $m_phone = preg_replace('/[^0-9]/', '', $mr['recipient_phone']);
            $k10 = strlen($m_phone) >= 10 ? substr($m_phone, -10) : $m_phone;
            if (!empty($k10) && !isset($seen[$k10])) {
                $u_count = $unread_counts_map[$k10] ?? 0;
                $is_free = isset($free_window_map[$k10]);

                $contacts_map[] = [
                    'id'                   => count($contacts_map) + 1,
                    'contact_name'         => '+' . $m_phone,
                    'phone_number'         => $m_phone,
                    'is_saved'             => false,
                    'last_msg'             => $mr['message_text'] ?: 'Tap to open chat',
                    'last_time'            => !empty($mr['created_at']) ? formatTimeISTAction($mr['created_at']) : '',
                    'unread_count'         => $u_count,
                    'is_free_reply_active' => $is_free,
                    'is_archived'          => false,
                    'is_important'         => false,
                    'raw_last_time'        => $mr['created_at'] ?? '',
                    'max_id'               => (int)$mr['id']
                ];
                $seen[$k10] = true;
            }
        }

        // Sorting by max message ID and timestamp
        usort($contacts_map, function($a, $b) {
            $idA = $a['max_id'] ?? 0;
            $idB = $b['max_id'] ?? 0;
            if ($idA !== $idB) {
                return ($idA > $idB) ? -1 : 1;
            }
            $tA = !empty($a['raw_last_time']) ? strtotime($a['raw_last_time']) : 0;
            $tB = !empty($b['raw_last_time']) ? strtotime($b['raw_last_time']) : 0;
            return ($tA > $tB) ? -1 : 1;
        });

        echo json_encode(['status' => true, 'contacts' => $contacts_map, 'total_unread' => $totalUnread]);
        exit;
    }

    // Save Contact
    if ($action === 'save_contact') {
        $name  = trim($_POST['name'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        if (!empty($name) && !empty($phone)) {
            $pdo->prepare("INSERT INTO contacts (user_id, contact_name, phone_number) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE contact_name = VALUES(contact_name)")
                ->execute([$user_id, $name, $phone]);
            echo json_encode(['status' => true, 'message' => 'Saved!']);
            exit;
        }
    }

    // Edit Contact
    if ($action === 'edit_contact') {
        $contact_id = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $raw_phone  = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');

        if (empty($name) || empty($raw_phone)) {
            echo json_encode(['status' => false, 'message' => 'Name and phone number cannot be empty.']);
            exit;
        }

        $phone = $raw_phone;
        if (strlen($phone) === 10) {
            $phone = '91' . $phone;
        } elseif (strlen($phone) === 11 && strpos($phone, '0') === 0) {
            $phone = '91' . substr($phone, 1);
        }

        $old_phone = '';
        if ($contact_id > 0) {
            $stmtOld = $pdo->prepare("SELECT phone_number FROM contacts WHERE id = ? AND user_id = ? LIMIT 1");
            $stmtOld->execute([$contact_id, $user_id]);
            $old_phone = $stmtOld->fetchColumn() ?: '';
        }

        if ($contact_id > 0) {
            $stmtUp = $pdo->prepare("UPDATE contacts SET contact_name = ?, phone_number = ? WHERE id = ? AND user_id = ?");
            $stmtUp->execute([$name, $phone, $contact_id, $user_id]);
        } else {
            $stmtUp = $pdo->prepare("UPDATE contacts SET contact_name = ?, phone_number = ? WHERE user_id = ? AND (phone_number = ? OR phone_number LIKE ?)");
            $stmtUp->execute([$name, $phone, $user_id, $phone, "%{$phone}"]);
        }

        if (!empty($old_phone) && $old_phone !== $phone) {
            $old10 = substr($old_phone, -10);
            $stmtMsgSync = $pdo->prepare("UPDATE messages SET recipient_phone = ? WHERE user_id = ? AND (recipient_phone = ? OR recipient_phone LIKE ?)");
            $stmtMsgSync->execute([$phone, $user_id, $old_phone, "%{$old10}"]);
        }

        echo json_encode(['status' => true, 'message' => 'Contact updated successfully!']);
        exit;
    }

    // Delete Contact
    if ($action === 'delete_contact') {
        $contact_id = (int)($_POST['id'] ?? 0);
        if ($contact_id > 0) {
            $pdo->prepare("DELETE FROM contacts WHERE id = ? AND user_id = ?")->execute([$contact_id, $user_id]);
            echo json_encode(['status' => true, 'message' => 'Contact deleted successfully.']);
            exit;
        }
    }

    // Billing Logs
    if ($action === 'fetch_billing_logs') {
        $from = $_GET['from_date'] ?? date('Y-m-01');
        $to   = $_GET['to_date'] ?? date('Y-m-d');
        
        $stmt = $pdo->prepare("SELECT id, recipient_phone, message_type, direction, sent_via, cost, created_at FROM messages WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC");
        $stmt->execute([$user_id, $from, $to]);
        $recs = $stmt->fetchAll();
        $total = 0;
        foreach ($recs as $r) { $total += (float)$r['cost']; }

        $wallet_recs = [];
        try {
            $stmt_w = $pdo->prepare("SELECT id, amount, type, status, created_at, utr_number, 'Wubachat (Business Entity)' as payee FROM wallet_transactions WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC");
            $stmt_w->execute([$user_id, $from, $to]);
            $wallet_recs = $stmt_w->fetchAll();
        } catch(Exception $ex) {
            try {
                $stmt_w = $pdo->prepare("SELECT id, amount, type, status, created_at, 'Wubachat (BusinessEntity)' as payee FROM wallet_transactions WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC");
                $stmt_w->execute([$user_id, $from, $to]);
                $wallet_recs = $stmt_w->fetchAll();
            } catch(Exception $e2) {}
        }

        echo json_encode([
            'status'         => true, 
            'total_spent'    => number_format($total, 2), 
            'records'        => $recs, 
            'wallet_records' => $wallet_recs
        ]);
        exit;
    }

} catch(Exception $e) {
    echo json_encode(['status' => false, 'message' => $e->getMessage()]);
}
?>