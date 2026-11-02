<?php
session_start();
require_once '../config.php';

// Agar user logged in nahi hai toh login page par bhejein
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if (isset($_GET['code'])) {
    $code = $_GET['code'];

    // 1. Code ko Access Token mein exchange karein
    $token_url = "https://graph.facebook.com/" . META_GRAPH_VERSION . "/oauth/access_token?" . http_build_query([
        'client_id' => META_APP_ID,
        'client_secret' => META_APP_SECRET,
        'redirect_uri' => META_REDIRECT_URI,
        'code' => $code
    ]);

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['access_token'])) {
        $user_access_token = $data['access_token'];

        // 2. WABA ID aur Phone Number details fetch karein
        $debug_url = "https://graph.facebook.com/" . META_GRAPH_VERSION . "/debug_token?input_token=" . $user_access_token . "&access_token=" . META_APP_ID . "|" . META_APP_SECRET;
        
        $ch = curl_init($debug_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $debug_res = curl_exec($ch);
        curl_close($ch);
        $debug_data = json_decode($debug_res, true);

        // Database mein save ya update karein
        $stmt = $conn->prepare("INSERT INTO user_whatsapp_accounts (user_id, access_token, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE access_token = ?, status = 'active'");
        $stmt->bind_param("iss", $user_id, $user_access_token, $user_access_token);
        $stmt->execute();
        $stmt->close();

        header("Location: ../dashboard.php?status=whatsapp_connected");
        exit();
    } else {
        echo "Error fetching token: " . htmlspecialchars($response);
    }
} else {
    echo "Authorization code missing.";
}
?>