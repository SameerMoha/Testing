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
    die("Connection failed: " . $conn->connect_error);
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
    contact TEXT
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
    $top = 20; // Adjusted to API max page size

    do {
        $url = rtrim($slUrl, '/') . '/BusinessPartners?$filter=' . rawurlencode($filter) . '&$top=' . $top . '&$skip=' . $skip . '&$count=true';
echo "Fetching records starting at skip=$skip...<br>";
    flush();
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


function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

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
    $notice = "Service Layer login failed — using local JSON fallback.";
} else {
    $bp = sl_getBusinessPartners($SL_URL, $COOKIEFILE);
    error_log("Attempting to read local JSON: $LOCAL_JSON");
    if ($bp === false) {
        $fromService = false;
        $data = read_local_json($LOCAL_JSON);
        $notice = "Service Layer fetch failed — using local JSON fallback.";
    } else {
        $fromService = true;
        $data = $bp;
        $notice = "Data loaded from Service Layer.";
    }
}

$items = [];
if (is_array($data) && array_key_exists('value', $data) && is_array($data['value'])) {
    $items = $data['value'];
}

// Insert into local DB
if ($conn && count($items) > 0) {
    // Truncate table to store fresh data
    $conn->query("TRUNCATE TABLE business_partners");
    $stmt = $conn->prepare("INSERT INTO business_partners (card_code, card_name, phone, city, county, email, credit_limit, current_balance, address, contact) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $row) {
        $card_code = $row['CardCode'] ?? '';
        $card_name = $row['CardName'] ?? '';
        $phone = $row['Phone1'] ?? $row['MobilePhone'] ?? '';
        $city = $row['City'] ?? '';
        $county = $row['County'] ?? '';
        $email = $row['EmailAddress'] ?? '';
        $credit_limit = isset($row['CreditLimit']) ? (float)$row['CreditLimit'] : 0;
        $current_balance = isset($row['CurrentAccountBalance']) ? (float)$row['CurrentAccountBalance'] : 0;
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
        $stmt->bind_param("ssssssddss", $card_code, $card_name, $phone, $city, $county, $email, $credit_limit, $current_balance, $address, $contact);
        $stmt->execute();
    }
    $stmt->close();
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
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>SAP B1 BusinessPartners</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 18px; }
        table { border-collapse: collapse; width: 100%; font-size:14px; }
        th, td { border: 1px solid #ddd; padding: 8px; vertical-align:top; }
        th { background: #f2f2f2; text-align: left; }
        tr:nth-child(even){background-color: #fafafa;}
        .notice { padding: 10px; margin-bottom: 12px; border-left: 4px solid #007bff; background: #f7fbff;}
        .small { font-size: 13px; color: #555; }
        .raw { margin-top: 14px; white-space: pre-wrap; background:#111; color:#eee; padding:12px; border-radius:6px; max-height:300px; overflow:auto;}
        code { background:#eee; padding:2px 6px; border-radius:4px; }
    </style>
</head>
<body>
    <h2>SAP B1 BusinessPartners </h2>
   
    <?php if (count($items) === 0): ?>
        <p>No BusinessPartners found in the response.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>CardCode</th>
                    <th>CardName</th>
                    <th>Phone</th>
                    <th>City</th>
                    <th>County</th>
                    <th>Email</th>
                    <th>CreditLimit</th>
                    <th>CurrentBalance</th>
                    <th>Primary Address (first)</th>
                    <th>Primary Contact (first)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $i => $row): 
                $addrLines = [];
                $street = get_first_address_field($row, 'Street');
                $block  = get_first_address_field($row, 'Block');
                $city   = get_first_address_field($row, 'City');
                $zip    = get_first_address_field($row, 'ZipCode');
                $country= get_first_address_field($row, 'Country');
                if ($street) $addrLines[] = $street;
                if ($block)  $addrLines[] = $block;
                $addrLines[] = trim(($city ? $city : '') . ($zip ? ' ' . $zip : ''));
                if ($country) $addrLines[] = $country;

                $contactName  = get_first_contact_field($row, 'Name');
                $contactPhone = get_first_contact_field($row, 'Telephone1') ?: get_first_contact_field($row, 'MobilePhone');
                $contactEmail = get_first_contact_field($row, 'E_Mail') ?: get_first_contact_field($row, 'EmailAddress');

            ?>
                <tr>
                    <td><?= ($i+1) ?></td>
                    <td><?= h($row['CardCode'] ?? '') ?></td>
                    <td><?= h($row['CardName'] ?? '') ?></td>
                    <td><?= h($row['Phone1'] ?? $row['MobilePhone'] ?? '') ?></td>
                    <td><?= h($row['City'] ?? '') ?></td>
                    <td><?= h($row['County'] ?? '') ?></td>
                    <td><?= h($row['EmailAddress'] ?? '') ?></td>
                    <td style="text-align:right;"><?= isset($row['CreditLimit']) ? number_format((float)$row['CreditLimit'], 2) : '' ?></td>
                    <td style="text-align:right;"><?= isset($row['CurrentAccountBalance']) ? number_format((float)$row['CurrentAccountBalance'], 2) : '' ?></td>
                    <td>
                        <?= h(implode('<br>', array_filter($addrLines))) ?>
                    </td>
                    <td>
                        <strong><?= h($contactName) ?></strong><br>
                        <?= h($contactPhone) ?><br>
                        <?= h($contactEmail) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="raw">
            <strong>Raw JSON preview (top):</strong>
            <pre><?= h(json_encode(array_slice($items, 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    <?php endif; ?>

    <hr>
    
</body>
</html>
