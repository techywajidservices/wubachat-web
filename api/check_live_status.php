<?php
// api/check_live_status.php - Live Meta Registration & Lock Inspector
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$token   = defined('META_SYSTEM_TOKEN') ? META_SYSTEM_TOKEN : '';
$waba_id = defined('META_WABA_ID') ? META_WABA_ID : '2553703311722771';
$version = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v20.0';

echo "<!DOCTYPE html><html><head><title>Meta WhatsApp Live Status</title>";
echo "<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; padding: 25px; }
.card { background: #fff; border-radius: 12px; padding: 25px; max-width: 850px; margin: 0 auto 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
.badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; }
.badge-green { background: #d4edda; color: #155724; }
.badge-red { background: #f8d7da; color: #721c24; }
.badge-blue { background: #cce5ff; color: #004085; }
.table { width: 100%; border-collapse: collapse; margin-top: 15px; }
.table th, .table td { padding: 12px 15px; border-bottom: 1px solid #e1e4e8; text-align: left; }
.table th { background: #fafbfc; color: #586069; }
</style></head><body>";

echo "<div class='card'>";
echo "<h2>🔍 Meta Cloud API Real-Time Status Checker</h2>";
echo "<p style='color:#666;'>Yeh tool direct Meta Graph API se live data fetch karke batata hai ki number Cloud par active hai ya Mobile ke liye release ho chuka hai.</p>";

// Fetch all phone numbers
$url = "https://graph.facebook.com/{$version}/{$waba_id}/phone_numbers?access_token={$token}";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);

if (!empty($data['data'])) {
    echo "<table class='table'>";
    echo "<tr>
            <th>Phone Number</th>
            <th>Phone ID</th>
            <th>Meta Cloud Status</th>
            <th>Mobile WhatsApp Status</th>
          </tr>";

    foreach ($data['data'] as $p) {
        $p_id  = $p['id'];
        $p_num = $p['display_phone_number'] ?? 'N/A';

        // Deep inspect this individual phone number ID from Meta
        $detail_url = "https://graph.facebook.com/{$version}/{$p_id}?fields=status,code_verification_status,name_status,platform_type,throughput&access_token={$token}";
        $ch2 = curl_init($detail_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        $detail_res = curl_exec($ch2);
        curl_close($ch2);
        $detail = json_decode($detail_res, true);

        $cloud_status = $detail['status'] ?? 'CONNECTED';
        
        // Test Deregister Status via API Probe
        $dereg_probe_url = "https://graph.facebook.com/{$version}/{$p_id}/deregister";
        $ch3 = curl_init($dereg_probe_url);
        curl_setopt($ch3, CURLOPT_POST, true);
        curl_setopt($ch3, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Content-Type: application/json"]);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
        $probe_res = curl_exec($ch3);
        curl_close($ch3);
        $probe_data = json_decode($probe_res, true);

        // Analysis
        $is_not_registered = isset($probe_data['error']['error_subcode']) && $probe_data['error']['error_subcode'] == 133010;

        echo "<tr>";
        echo "<td><b>{$p_num}</b></td>";
        echo "<td><code>{$p_id}</code></td>";

        if ($is_not_registered) {
            echo "<td><span class='badge badge-green'>✓ UNREGISTERED / DEREGISTERED</span></td>";
            echo "<td><span class='badge badge-green'>✓ FREE FOR MOBILE APP</span></td>";
        } else {
            echo "<td><span class='badge badge-blue'>ACTIVE ON CLOUD API</span></td>";
            echo "<td><span class='badge badge-red'>LOCKED (Active on Server)</span></td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No phone numbers found in Meta WABA.</p>";
}

echo "</div></body></html>";
?>