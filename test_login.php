<?php
// Simple login test with detailed logging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SAP B1 Service Layer config
$SL_URL     = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME   = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD   = 'A2r@h@R001';
$COMPANYDB  = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';

echo "=== LOGIN TEST ===" . PHP_EOL;
echo "URL: $SL_URL" . PHP_EOL;
echo "Username: $USERNAME" . PHP_EOL;
echo "CompanyDB: $COMPANYDB" . PHP_EOL;
echo "Cookie file: $COOKIEFILE" . PHP_EOL;
echo "Cookie file exists: " . (file_exists($COOKIEFILE) ? 'YES' : 'NO') . PHP_EOL;

$loginUrl = rtrim($SL_URL, '/') . '/Login';
$payload = json_encode([
    'UserName'  => $USERNAME,
    'Password'  => $PASSWORD,
    'CompanyDB' => $COMPANYDB
]);

echo PHP_EOL . "Login URL: $loginUrl" . PHP_EOL;
echo "Payload: $payload" . PHP_EOL;

$ch = curl_init($loginUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_COOKIEJAR      => $COOKIEFILE,
    CURLOPT_COOKIEFILE     => $COOKIEFILE,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_VERBOSE        => true
]);

echo PHP_EOL . "Making cURL request..." . PHP_EOL;
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$curl_info = curl_getinfo($ch);
curl_close($ch);

echo PHP_EOL . "=== RESPONSE ===" . PHP_EOL;
echo "HTTP Code: $http" . PHP_EOL;
echo "cURL Error: " . ($curl_error ?: 'None') . PHP_EOL;
echo "Response Length: " . strlen($resp) . PHP_EOL;
echo "Response: " . PHP_EOL . $resp . PHP_EOL;

echo PHP_EOL . "=== cURL INFO ===" . PHP_EOL;
echo "Total Time: " . $curl_info['total_time'] . " seconds" . PHP_EOL;
echo "Connect Time: " . $curl_info['connect_time'] . " seconds" . PHP_EOL;
echo "SSL Verify Result: " . $curl_info['ssl_verify_result'] . PHP_EOL;

if ($resp !== false && $http >= 200 && $http < 300) {
    $json = json_decode($resp, true);
    if ($json && isset($json['SessionId'])) {
        echo PHP_EOL . "✅ LOGIN SUCCESS!" . PHP_EOL;
        echo "Session ID: " . $json['SessionId'] . PHP_EOL;
        echo "Session Timeout: " . ($json['SessionTimeout'] ?? 'N/A') . " minutes" . PHP_EOL;
    } else {
        echo PHP_EOL . "❌ LOGIN FAILED - Invalid JSON response" . PHP_EOL;
        echo "JSON Error: " . json_last_error_msg() . PHP_EOL;
    }
} else {
    echo PHP_EOL . "❌ LOGIN FAILED - HTTP Error" . PHP_EOL;
}

echo PHP_EOL . "Cookie file after request exists: " . (file_exists($COOKIEFILE) ? 'YES' : 'NO') . PHP_EOL;
if (file_exists($COOKIEFILE)) {
    echo "Cookie file size: " . filesize($COOKIEFILE) . " bytes" . PHP_EOL;
}
?>