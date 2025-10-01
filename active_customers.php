<?php
set_time_limit(1800);
@ini_set('max_execution_time', '1800');

// SAP B1 Service Layer config (same as other files)
$SL_URL     = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME   = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD   = 'A2r@h@R001';
$COMPANYDB  = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';

// MySQL database config
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

// Database connection
$db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db_conn->connect_error) {
    error_log("DEBUG: Database connection failed: " . $db_conn->connect_error);
    die("Database connection failed: " . $db_conn->connect_error);
} else {
    error_log("DEBUG: Database connected successfully");
}

// Create table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS active_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series INT,
    card_code VARCHAR(50) UNIQUE,
    card_name VARCHAR(255),
    valid_for VARCHAR(10),
    price_list INT DEFAULT 1,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($db_conn->query($table_sql) === TRUE) {
    error_log("DEBUG: Table active_customers created or already exists");
} else {
    error_log("DEBUG: Error creating table: " . $db_conn->error);
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

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

// Fetch active customers equivalent to:
// SELECT T1."Series", T0."CardCode", T0."CardName", T0."validFor"
// FROM OCRD T0 INNER JOIN NNM1 T1 ON T0."Series" = T1."Series"
// WHERE T0."CardType" = 'C' AND T0."CardCode" LIKE 'C%' AND T0."validFor" = 'Y'
function sl_get_active_customers_only($slUrl, $cookieFile) {
    // Fetch only customers; include validity fields and filter client-side
    $filter = "CardType eq 'C'";
    $select = '$select=Series,CardCode,CardName,Valid,PriceListNum';
    $top    = 20; // Service Layer max page size is typically 20
    $skip   = 0;
    $all    = [];

    do {
        $url = rtrim($slUrl, '/') . '/BusinessPartners?$filter=' . rawurlencode($filter) . '&' . $select . '&$count=true&$top=' . $top . '&$skip=' . $skip;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Add logging
        error_log("DEBUG: URL: $url");
        error_log("DEBUG: HTTP Code: $http");
        error_log("DEBUG: Response: $resp");

        if ($resp === false || $http < 200 || $http >= 300) return ['value' => [], 'error' => 'HTTP ' . $http . ' - Response: ' . $resp];
        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) return ['value' => []];
        $items = $json['value'];
        $all = array_merge($all, $items);
        $skip += $top;
    } while (count($items) > 0);

    // Add logging for total fetched
    error_log("DEBUG: Total fetched items: " . count($all));

    // Apply active filter equivalent to: CardCode LIKE 'C%' AND ValidFor = 'Y'
    $filtered = [];
    foreach ($all as $r) {
        $code = $r['CardCode'] ?? '';
        if ($code === '' || strtoupper($code[0]) !== 'C') continue;
        // Valid is 'tYES'/'tNO' for active/inactive
        $isActive = isset($r['Valid']) && strtoupper($r['Valid']) === 'TYES';
        if ($isActive) $filtered[] = $r;
    }

    // Add logging for filtered results
    error_log("DEBUG: Filtered active customers: " . count($filtered));

    return ['value' => $filtered];
}

$ok = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
if ($ok === false) {
    $items = [];
    $error = 'Service Layer login failed';
} else {
    $res = sl_get_active_customers_only($SL_URL, $COOKIEFILE);
    $items = $res['value'] ?? [];
    $error = $res['error'] ?? '';

    // Insert fetched data into database
    if (!empty($items)) {
        $insert_count = 0;
        foreach ($items as $customer) {
            $series = intval($customer['Series'] ?? 0);
            $card_code = $db_conn->real_escape_string($customer['CardCode'] ?? '');
            $card_name = $db_conn->real_escape_string($customer['CardName'] ?? '');
            $valid_for = ($customer['Valid'] ?? '') === 'tYES' ? 'Y' : 'N';
            $price_list = intval($customer['PriceListNum'] ?? 1);

            $insert_sql = "INSERT INTO active_customers (series, card_code, card_name, valid_for, price_list)
                           VALUES ($series, '$card_code', '$card_name', '$valid_for', $price_list)
                           ON DUPLICATE KEY UPDATE card_name='$card_name', valid_for='$valid_for', price_list=$price_list, fetched_at=CURRENT_TIMESTAMP";
            if ($db_conn->query($insert_sql) === TRUE) {
                $insert_count++;
            } else {
                error_log("DEBUG: Error inserting customer $card_code: " . $db_conn->error);
            }
        }
        error_log("DEBUG: Inserted/updated $insert_count customers into database");
    } else {
        error_log("DEBUG: No customers to insert");
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Active Customers</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 18px; }
        table { border-collapse: collapse; width: 100%; font-size:14px; }
        th, td { border: 1px solid #ddd; padding: 8px; vertical-align:top; }
        th { background: #f2f2f2; text-align: left; }
        tr:nth-child(even){background-color: #fafafa;}
        .notice { padding:10px; margin:12px 0; border-left:4px solid #dc3545; background:#fff7f7; }
    </style>
</head>
<body>
    <h2>Active Customers</h2>
    <?php if (!empty($error)): ?>
        <div class="notice"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if (count($items) === 0): ?>
        <p>No records found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Series (ID)</th>
                    <th>CardCode</th>
                    <th>CardName</th>
                    <th>ValidFor</th>
                    <th>Price List</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $i => $r):
                $validFor = isset($r['Valid']) ? ($r['Valid'] === 'tYES' ? 'Y' : 'N') : '';
            ?>
                <tr>
                    <td><?= ($i+1) ?></td>
                    <td><?= h($r['Series'] ?? '') ?></td>
                    <td><?= h($r['CardCode'] ?? '') ?></td>
                    <td><?= h($r['CardName'] ?? '') ?></td>
                    <td><?= h($validFor) ?></td>
                    <td><?= h($r['PriceListNum'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>


