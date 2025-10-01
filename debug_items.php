<?php
set_time_limit(1800);

// SAP B1 Service Layer config
$SL_URL = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD = 'A2r@h@R001';
$COMPANYDB = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';

function sl_login($slUrl, $username, $password, $companyDB, $cookieFile) {
    $loginUrl = rtrim($slUrl, '/') . '/Login';
    $payload = json_encode([
        'UserName' => $username,
        'Password' => $password,
        'CompanyDB' => $companyDB
    ]);

    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "Login HTTP Code: $http\n";
    if ($resp === false || $http < 200 || $http >= 300) return false;
    $json = json_decode($resp, true);
    return $json ?: false;
}

echo "Starting debug...\n";

$slLogin = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
if ($slLogin === false) {
    echo "Login failed\n";
    exit;
}

echo "Login successful. Testing pagination...\n";

// Test different page sizes
for ($i = 0; $i < 3; $i++) {
    $skip = $i * 100;
    $url = rtrim($SL_URL, '/') . '/Items?$select=ItemCode,ItemName&$top=100&$skip=' . $skip;
    echo "\nTesting page " . ($i + 1) . " (skip=$skip): $url\n";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_COOKIEFILE => $COOKIEFILE,
        CURLOPT_COOKIEJAR => $COOKIEFILE,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 60
    ]);
    
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $http\n";
    
    if ($http == 200) {
        $json = json_decode($resp, true);
        $itemCount = count($json['value'] ?? []);
        echo "Items in this page: $itemCount\n";
        
        if ($itemCount > 0) {
            echo "First item: " . ($json['value'][0]['ItemCode'] ?? 'N/A') . "\n";
            echo "Last item: " . ($json['value'][$itemCount-1]['ItemCode'] ?? 'N/A') . "\n";
        }
        
        if ($itemCount < 100) {
            echo "This appears to be the last page\n";
            break;
        }
    } else {
        echo "Error response: " . substr($resp, 0, 200) . "\n";
        break;
    }
}
?>