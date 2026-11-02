<?php
// api/save_contact.php - Universal Multi-Field Safe Contact Saver (Universal Number Support)
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? ($_GET['action'] ?? 'save');

try {
    // 1. DELETE ACTION
    if ($action === 'delete' || $action === 'delete_contact') {
        $contact_id = (int)($_POST['id'] ?? ($_POST['contact_id'] ?? 0));
        $phone      = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? ($_POST['phone_number'] ?? ''));

        if ($contact_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM contacts WHERE id = ?");
            $stmt->execute([$contact_id]);
        } elseif (!empty($phone)) {
            $stmt = $pdo->prepare("DELETE FROM contacts WHERE phone_number LIKE ? OR phone_number = ?");
            $stmt->execute(["%" . substr($phone, -10), $phone]);
        }
        echo json_encode(['status' => true, 'message' => 'Contact deleted successfully!']);
        exit;
    }

    // 2. SAVE / UPDATE ACTION
    $name  = trim($_POST['contact_name'] ?? ($_POST['name'] ?? ''));
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone_number'] ?? ($_POST['phone'] ?? ''));

    // Removed strict length restrictions to allow all types of phone numbers & shortcodes
    if (empty($phone)) {
        echo json_encode(['status' => false, 'message' => 'Please provide a valid mobile number or identifier.']);
        exit;
    }

    if (empty($name)) {
        $name = 'Contact ' . substr($phone, -4);
    }

    $last10 = strlen($phone) >= 10 ? substr($phone, -10) : $phone;

    // Check if contact already exists
    $stmtCheck = $pdo->prepare("SELECT id FROM contacts WHERE phone_number LIKE ? OR phone_number = ?");
    $stmtCheck->execute(["%{$last10}", $phone]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $stmtUp = $pdo->prepare("UPDATE contacts SET contact_name = ?, user_id = ? WHERE id = ?");
        $stmtUp->execute([$name, $user_id, $existing['id']]);
    } else {
        $stmtIns = $pdo->prepare("INSERT INTO contacts (user_id, contact_name, phone_number) VALUES (?, ?, ?)");
        $stmtIns->execute([$user_id, $name, $phone]);
    }

    echo json_encode(['status' => true, 'message' => 'Contact saved successfully!']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>