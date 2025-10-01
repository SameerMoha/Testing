<?php 
set_time_limit(1000); // 5 minutes

$SL_URL     = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME   = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD   = 'A2r@h@R001';
$COMPANYDB  = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';
$LOCAL_JSON = __DIR__ . '/response(2).json';

// Local MySQL database setup
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
if ($conn->connect_error) {
    fwrite(STDERR, "Connection failed: " . $conn->connect_error . "\n");
    exit(1);
}

// Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS `$DB_NAME`");
$conn->select_db($DB_NAME);
error_log("Database '$DB_NAME' selected successfully");

// Create table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS business_partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_code VARCHAR(50),
    card_name VARCHAR(255),
    phone VARCHAR(50),
    city VARCHAR(100),
    county VARCHAR(100),
    email VARCHAR(255),
    credit_limit DECIMAL(15,2),
    current_balance DECIMAL(15,2),
    address TEXT,
    contact TEXT,
    price_list INT DEFAULT 1
)";
$conn->query($table_sql);

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

    if ($resp === false || $http < 200 || $http >= 300) {
        error_log("SL login failed with HTTP code: $http");
        return false;
    }
    $json = json_decode($resp, true);
    return $json ?: false;
}

function sl_getBusinessPartners($slUrl, $cookieFile, $filter = "CardType eq 'C' or CardType eq 'S' or CardType eq 'L'") {
    $allItems = [];
    $skip = 0;
    $top = 20; // API page size

    do {
        echo "Fetching records starting at skip=$skip...\n";
        $url = rtrim($slUrl, '/') . '/BusinessPartners?$filter=' . rawurlencode($filter) . '&$select=CardCode,CardName,Phone1,City,County,EmailAddress,CreditLimit,CurrentAccountBalance,PriceListNum&$top=' . $top . '&$skip=' . $skip . '&$count=true';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 20
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $http < 200 || $http >= 300) {
            error_log("SL fetch failed with HTTP code: $http");
            return false;
        }
        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) return false;

        $items = $json['value'];
        error_log("Fetched " . count($items) . " items, skip=$skip");
        $allItems = array_merge($allItems, $items);
        $skip += $top;
    } while (count($items) == $top);

    return ['value' => $allItems];
}

function read_local_json($file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        return json_decode($content, true) ?: ['value' => []];
    } else {
        error_log("Local JSON file not found: $file");
        return ['value' => []];
    }
}

$slLogin = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
error_log("SL login result: " . ($slLogin ? "success" : "failed"));
error_log("Attempting to read local JSON: $LOCAL_JSON");

if ($slLogin === false) {
    $fromService = false;
    $data = read_local_json($LOCAL_JSON);
    echo "Service Layer login failed — using local JSON fallback.\n";
} else {
    $bp = sl_getBusinessPartners($SL_URL, $COOKIEFILE);
    if ($bp === false) {
        $fromService = false;
        $data = read_local_json($LOCAL_JSON);
        echo "Service Layer fetch failed — using local JSON fallback.\n";
    } else {
        $fromService = true;
        $data = $bp;
        echo "Data loaded from Service Layer.\n";
    }
}

$items = [];
if (is_array($data) && array_key_exists('value', $data) && is_array($data['value'])) {
    $items = $data['value'];
}

// Insert into local DB
if ($conn && count($items) > 0) {
    $conn->query("TRUNCATE TABLE business_partners");
    $stmt = $conn->prepare("INSERT INTO business_partners (card_code, card_name, phone, city, county, email, credit_limit, current_balance, address, contact, price_list) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $row) {
        $card_code = $row['CardCode'] ?? '';
        $card_name = $row['CardName'] ?? '';
        $phone = $row['Phone1'] ?? $row['MobilePhone'] ?? '';
        $city = $row['City'] ?? '';
        $county = $row['County'] ?? '';
        $email = $row['EmailAddress'] ?? '';
        $credit_limit = isset($row['CreditLimit']) ? (float)$row['CreditLimit'] : 0;
        $current_balance = isset($row['CurrentAccountBalance']) ? (float)$row['CurrentAccountBalance'] : 0;
        $price_list = intval($row['PriceListNum'] ?? 1);

        // Address
        $addrLines = [];
        $street = get_first_address_field($row, 'Street');
        $block = get_first_address_field($row, 'Block');
        $city_addr = get_first_address_field($row, 'City');
        $zip = get_first_address_field($row, 'ZipCode');
        $country = get_first_address_field($row, 'Country');
        if ($street) $addrLines[] = $street;
        if ($block) $addrLines[] = $block;
        $addrLines[] = trim(($city_addr ? $city_addr : '') . ($zip ? ' ' . $zip : ''));
        if ($country) $addrLines[] = $country;
        $address = implode(', ', array_filter($addrLines));

        // Contact
        $contactName = get_first_contact_field($row, 'Name');
        $contactPhone = get_first_contact_field($row, 'Telephone1') ?: get_first_contact_field($row, 'MobilePhone');
        $contactEmail = get_first_contact_field($row, 'E_Mail') ?: get_first_contact_field($row, 'EmailAddress');
        $contact = trim($contactName . ' ' . $contactPhone . ' ' . $contactEmail);

        $stmt->bind_param("ssssssddssi", $card_code, $card_name, $phone, $city, $county, $email, $credit_limit, $current_balance, $address, $contact, $price_list);
        $stmt->execute();
    }
    $stmt->close();
    echo "Inserted " . count($items) . " records into business_partners table.\n";
}
$conn->close();

function get_first_address_field($row, $field) {
    if (!isset($row['BPAddresses']) || !is_array($row['BPAddresses']) || count($row['BPAddresses']) === 0) return '';
    return $row['BPAddresses'][0][$field] ?? '';
}
function get_first_contact_field($row, $field) {
    if (!isset($row['ContactEmployees']) || !is_array($row['ContactEmployees']) || count($row['ContactEmployees']) === 0) return '';
    return $row['ContactEmployees'][0][$field] ?? '';
}
