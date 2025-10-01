<?php
// Debug the items insertion issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test error_log function
error_log("DEBUG TEST: This is a test log entry");
echo "Testing error_log function..." . PHP_EOL;

// SAP B1 Service Layer config
$SL_URL     = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME   = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD   = 'A2r@h@R001';
$COMPANYDB  = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';

// Database config
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

echo "=== TESTING DATABASE CONNECTION ===" . PHP_EOL;
$db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db_conn->connect_error) {
    echo "Database connection failed: " . $db_conn->connect_error . PHP_EOL;
    exit;
} else {
    echo "Database connected successfully" . PHP_EOL;
}

// Test table existence
$tables = ['items', 'price_lists', 'item_prices'];
foreach ($tables as $table) {
    $result = $db_conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        $count = $db_conn->query("SELECT COUNT(*) as cnt FROM $table");
        $row = $count->fetch_assoc();
        echo "$table table exists with " . $row['cnt'] . " records" . PHP_EOL;
    } else {
        echo "$table table does NOT exist" . PHP_EOL;
    }
}

// Login function (simplified)
function sl_login($slUrl, $username, $password, $companyDB, $cookieFile) {
    $loginUrl = rtrim($slUrl, '/') . '/Login';
    $payload = json_encode([
        'UserName'  => $username,
        'Password'  => $password,
        'CompanyDB' => $companyDB
    ]);

    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 20
    ]);
    
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($resp === false || $http < 200 || $http >= 300) return false;
    $json = json_decode($resp, true);
    return $json ?: false;
}

// Test items fetch (first page only)
echo PHP_EOL . "=== TESTING ITEMS FETCH ===" . PHP_EOL;
$login = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
if ($login === false) {
    echo "Login failed" . PHP_EOL;
    exit;
}
echo "Login successful" . PHP_EOL;

$filter = "Valid eq 'tYES'";
$select = '$select=ItemCode,ItemName,Valid';
$url = rtrim($SL_URL, '/') . '/Items?$filter=' . rawurlencode($filter) . '&' . $select . '&$top=5';

echo "Testing URL: $url" . PHP_EOL;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET        => true,
    CURLOPT_COOKIEFILE     => $COOKIEFILE,
    CURLOPT_COOKIEJAR      => $COOKIEFILE,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 30
]);

$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http" . PHP_EOL;
echo "Response length: " . strlen($resp) . PHP_EOL;

if ($http == 200) {
    $json = json_decode($resp, true);
    if ($json && isset($json['value'])) {
        $items = $json['value'];
        echo "Items fetched: " . count($items) . PHP_EOL;
        
        if (count($items) > 0) {
            echo PHP_EOL . "=== SAMPLE ITEMS ===" . PHP_EOL;
            for ($i = 0; $i < min(3, count($items)); $i++) {
                echo "Item " . ($i+1) . ": " . json_encode($items[$i]) . PHP_EOL;
            }
            
            echo PHP_EOL . "=== TESTING ITEM INSERTION ===" . PHP_EOL;
            $test_item = $items[0];
            $item_code = $db_conn->real_escape_string($test_item['ItemCode'] ?? '');
            $item_name = $db_conn->real_escape_string($test_item['ItemName'] ?? '');
            $valid_for = ($test_item['Valid'] ?? '') === 'tYES' ? 'Y' : 'N';
            
            echo "Test item: Code=$item_code, Name=$item_name, Valid=$valid_for" . PHP_EOL;
            
            $insert_sql = "INSERT INTO items (item_code, item_name, valid_for, price_list)
                           VALUES ('$item_code', '$item_name', '$valid_for', 1)
                           ON DUPLICATE KEY UPDATE item_name='$item_name', valid_for='$valid_for', price_list=1, fetched_at=CURRENT_TIMESTAMP";
            
            echo "SQL: $insert_sql" . PHP_EOL;
            
            if ($db_conn->query($insert_sql) === TRUE) {
                echo "✅ Item insertion successful" . PHP_EOL;
            } else {
                echo "❌ Item insertion failed: " . $db_conn->error . PHP_EOL;
            }
        }
    } else {
        echo "Invalid JSON response" . PHP_EOL;
    }
} else {
    echo "API Error: " . substr($resp, 0, 200) . PHP_EOL;
}

$db_conn->close();
?>