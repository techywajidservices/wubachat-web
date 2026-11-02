<?php
// api/import_contacts.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id = $_SESSION['user_id'];

if (empty($_FILES['file']['tmp_name'])) {
    echo json_encode(['status' => false, 'message' => 'Please select a CSV or TXT file to upload.']);
    exit;
}

$file = $_FILES['file']['tmp_name'];
$handle = fopen($file, 'r');
if (!$handle) {
    echo json_encode(['status' => false, 'message' => 'Unable to read the uploaded file.']);
    exit;
}

$imported_count = 0;
$skipped_count  = 0;

try {
    $stmt_check = $pdo->prepare("SELECT id FROM contacts WHERE user_id = ? AND phone_number = ?");
    $stmt_insert = $pdo->prepare("INSERT INTO contacts (user_id, contact_name, phone_number) VALUES (?, ?, ?)");

    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (empty($row[0])) continue;

        // Auto-detect format: [Name, Phone] ya sirf [Phone]
        if (isset($row[1])) {
            $name  = trim($row[0]);
            $phone = preg_replace('/[^0-9]/', '', $row[1]);
        } else {
            $phone = preg_replace('/[^0-9]/', '', $row[0]);
            $name  = 'Contact ' . substr($phone, -4);
        }

        if (strlen($phone) < 10) {
            $skipped_count++;
            continue;
        }

        // Check if contact already exists
        $stmt_check->execute([$user_id, $phone]);
        if ($stmt_check->fetch()) {
            $skipped_count++;
            continue;
        }

        $stmt_insert->execute([$user_id, $name ?: 'Customer', $phone]);
        $imported_count++;
    }
    fclose($handle);

    echo json_encode([
        'status'  => true,
        'message' => "Import complete: $imported_count contacts added ($skipped_count skipped/duplicates)."
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Import error: ' . $e->getMessage()]);
}
?>