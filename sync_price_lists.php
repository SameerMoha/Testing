<?php
set_time_limit(1800);
@ini_set('max_execution_time', '1800');

// SAP B1 Service Layer config
$SL_URL = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD = 'A2r@h@R001';
$COMPANYDB = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';
$LOCAL_JSON_PL = __DIR__ . '/response_price_lists.json';
$LOCAL_JSON_SP = __DIR__ . '/response_special_prices.json';

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

// Create tables if not exist
$table_sql1 = "CREATE TABLE IF NOT EXISTS price_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    price_list_code INT UNIQUE,
    price_list_name VARCHAR(255),
    base_price_list INT,
    is_gross_price VARCHAR(10),
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($db_conn->query($table_sql1) === TRUE) {
    error_log("DEBUG: Table price_lists created or already exists");
} else {
    error_log("DEBUG: Error creating price_lists table: " . $db_conn->error);
}

$table_sql2 = "CREATE TABLE IF NOT EXISTS special_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50),
    price_list_code INT,
    price DECIMAL(10,2),
    currency VARCHAR(10),
    discount_percent DECIMAL(5,2),
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_item_price (item_code, price_list_code)
)";
if ($db_conn->query($table_sql2) === TRUE) {
    error_log("DEBUG: Table special_prices created or already exists");
} else {
    error_log("DEBUG: Error creating special_prices table: " . $db_conn->error);
}

$table_sql3 = "CREATE TABLE IF NOT EXISTS item_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50),
    item_name VARCHAR(255),
    price_list_code INT,
    price DECIMAL(10,2),
    currency VARCHAR(10),
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_item_pricelist (item_code, price_list_code)
)";
if ($db_conn->query($table_sql3) === TRUE) {
    error_log("DEBUG: Table item_prices created or already exists");
} else {
    error_log("DEBUG: Error creating item_prices table: " . $db_conn->error);
}

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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_VERBOSE => true
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    error_log("DEBUG: Login HTTP Code: $http");
    error_log("DEBUG: Login Response: " . substr($resp, 0, 500));
    if ($error) {
        error_log("DEBUG: Login cURL Error: $error");
    }
    
    if ($resp === false || $http < 200 || $http >= 300) return false;
    $json = json_decode($resp, true);
    return $json ?: false;
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

function sl_get_price_lists($slUrl, $cookieFile) {
    $select = '$select=PriceListNo,PriceListName,BasePriceList,IsGrossPrice';
    $top = 100;
    $skip = 0;
    $maxPages = 100; // Increased limit
    $pages = 0;
    $all = [];
    $totalCount = 0;
    $hasMore = true;

    do {
        $pages++;
        $url = rtrim($slUrl, '/') . '/PriceLists?' . $select . '&$count=true&$top=' . $top . '&$skip=' . $skip;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60, // Increased timeout
            CURLOPT_VERBOSE => true
        ]);
        
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        error_log("DEBUG: PriceLists Page $pages URL: $url");
        error_log("DEBUG: PriceLists HTTP Code: $http");
        if ($error) {
            error_log("DEBUG: PriceLists cURL Error: $error");
        }
        error_log("DEBUG: PriceLists Response length: " . strlen($resp));

        if ($resp === false || $http < 200 || $http >= 300) {
            error_log("DEBUG: PriceLists API Error - HTTP $http: " . substr($resp, 0, 500));
            return ['value' => $all, 'error' => 'HTTP ' . $http . ' - Response: ' . substr($resp, 0, 200), 'total_fetched' => count($all)];
        }
        
        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) {
            error_log("DEBUG: PriceLists Invalid JSON: " . json_last_error_msg());
            return ['value' => $all, 'error' => 'Invalid JSON response', 'total_fetched' => count($all)];
        }
        
        $items = $json['value'];
        $all = array_merge($all, $items);
        
        // Get total count from first response
        if ($pages === 1 && isset($json['@odata.count'])) {
            $totalCount = intval($json['@odata.count']);
            error_log("DEBUG: Total price lists available: $totalCount");
        }
        
        $skip += $top;
        $fetchedThisPage = count($items);
        error_log("DEBUG: Fetched $fetchedThisPage price lists on page $pages, total so far: " . count($all));
        
        // Continue if we got full page OR if we haven't reached total count
        $hasMore = ($fetchedThisPage == $top || count($all) < $totalCount);
        
    } while ($hasMore && $pages < $maxPages && $fetchedThisPage > 0);

    error_log("DEBUG: Completed. Total fetched price lists: " . count($all) . " after $pages pages");
    return ['value' => $all, 'total_fetched' => count($all), 'pages' => $pages];
}

function sl_get_item_prices_comprehensive($slUrl, $cookieFile) {
    // Get Items with basic info first, then fetch ItemPrices collection
    $select = '$select=ItemCode,ItemName,ItemPrices';
    $top = 100; // Increased for efficiency
    $skip = 0;
    $maxPages = 1000; // Increased to handle large datasets
    $pages = 0;
    $all = [];
    $totalCount = 0;
    $hasMore = true;

    do {
        $pages++;
        $url = rtrim($slUrl, '/') . '/Items?' . $select . '&$count=true&$top=' . $top . '&$skip=' . $skip;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 300, // Increased timeout for large datasets
            CURLOPT_VERBOSE => true
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        error_log("DEBUG: Items Page $pages URL: $url");
        error_log("DEBUG: Items HTTP Code: $http");
        if ($error) {
            error_log("DEBUG: Items cURL Error: $error");
        }
        error_log("DEBUG: Items Response length: " . strlen($resp));

        if ($resp === false || $http < 200 || $http >= 300) {
            error_log("DEBUG: Items API Error - HTTP $http: " . $resp);
            return ['value' => $all, 'error' => 'HTTP ' . $http . ' - Response: ' . substr($resp, 0, 200), 'total_fetched' => count($all)];
        }

        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) {
            error_log("DEBUG: Items Invalid JSON: " . json_last_error_msg());
            return ['value' => $all, 'error' => 'Invalid JSON response', 'total_fetched' => count($all)];
        }

        $items = $json['value'];
        $all = array_merge($all, $items);

        // Get total count from first response
        if ($pages === 1 && isset($json['@odata.count'])) {
            $totalCount = intval($json['@odata.count']);
            error_log("DEBUG: Total items available: $totalCount");
        }
        
        $skip += $top;
        $fetchedThisPage = count($items);
        error_log("DEBUG: Fetched $fetchedThisPage items on page $pages, total so far: " . count($all) . ($totalCount > 0 ? " / $totalCount" : ""));
        
        // Continue if we got a full page (this means there might be more)
        $hasMore = ($fetchedThisPage == $top);

    } while ($hasMore && $pages < $maxPages && $fetchedThisPage > 0);

    error_log("DEBUG: Completed. Total fetched items: " . count($all) . " after $pages pages");
    return ['value' => $all, 'total_fetched' => count($all), 'pages' => $pages];
}

function sl_get_item_prices($slUrl, $cookieFile) {
    $select = '$select=ItemCode,PriceListNum,Price,Currency';
    $top = 100;
    $skip = 0;
    $maxPages = 200;
    $pages = 0;
    $all = [];
    $totalCount = 0;
    $hasMore = true;

    do {
        $pages++;
        $url = rtrim($slUrl, '/') . '/SpecialPrices?' . $select . '&$count=true&$top=' . $top . '&$skip=' . $skip;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_VERBOSE => true
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        error_log("DEBUG: SpecialPrices Page $pages URL: $url");
        error_log("DEBUG: SpecialPrices HTTP Code: $http");
        if ($error) {
            error_log("DEBUG: SpecialPrices cURL Error: $error");
        }
        error_log("DEBUG: SpecialPrices Response length: " . strlen($resp));

        if ($resp === false || $http < 200 || $http >= 300) {
            error_log("DEBUG: SpecialPrices API Error - HTTP $http: " . $resp);
            error_log("DEBUG: SpecialPrices Full Response: " . $resp);
            error_log("DEBUG: SpecialPrices cURL Info: " . print_r(curl_getinfo($ch), true));
            return ['value' => $all, 'error' => 'HTTP ' . $http . ' - Response: ' . substr($resp, 0, 200), 'total_fetched' => count($all)];
        }

        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) {
            error_log("DEBUG: SpecialPrices Invalid JSON: " . json_last_error_msg());
            return ['value' => $all, 'error' => 'Invalid JSON response', 'total_fetched' => count($all)];
        }

        $items = $json['value'];
        $all = array_merge($all, $items);

        // Get total count from first response
        if ($pages === 1 && isset($json['@odata.count'])) {
            $totalCount = intval($json['@odata.count']);
            error_log("DEBUG: Total special prices available: $totalCount");
        }

        $skip += $top;
        $fetchedThisPage = count($items);
        error_log("DEBUG: Fetched $fetchedThisPage special prices on page $pages, total so far: " . count($all));

        // Continue if we got full page OR if we haven't reached total count
        $hasMore = ($fetchedThisPage == $top || count($all) < $totalCount);

    } while ($hasMore && $pages < $maxPages && $fetchedThisPage > 0);

    error_log("DEBUG: Completed. Total fetched special prices: " . count($all) . " after $pages pages");
    return ['value' => $all, 'total_fetched' => count($all), 'pages' => $pages];
}

// Execute sync
echo "Starting SAP B1 Service Layer sync...\n";

$slLogin = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
error_log("SL login result: " . ($slLogin ? "success" : "failed"));

if ($slLogin === false) {
    echo "Service Layer login failed — using local JSON fallback.\n";
    $data_pl = read_local_json($LOCAL_JSON_PL);
    $data_sp = read_local_json($LOCAL_JSON_SP);
} else {
    echo "Login successful. Fetching price lists...\n";
    $pl = sl_get_price_lists($SL_URL, $COOKIEFILE);
    if (isset($pl['error'])) {
        echo "Service Layer fetch failed for price lists: " . $pl['error'] . " — using local JSON fallback.\n";
        $data_pl = read_local_json($LOCAL_JSON_PL);
    } else {
        $data_pl = $pl;
        echo "Successfully fetched " . $pl['total_fetched'] . " price lists in " . $pl['pages'] . " pages.\n";
    }

    echo "Fetching comprehensive item prices...\n";
    $ip = sl_get_item_prices_comprehensive($SL_URL, $COOKIEFILE);
    if (isset($ip['error'])) {
        echo "Service Layer fetch failed for comprehensive item prices: " . $ip['error'] . " — skipping comprehensive prices.\n";
        $data_ip = ['value' => []];
    } else {
        $data_ip = $ip;
        echo "Successfully fetched " . $ip['total_fetched'] . " items with prices in " . $ip['pages'] . " pages.\n";
    }

    echo "Fetching special prices...\n";
    $sp = sl_get_item_prices($SL_URL, $COOKIEFILE);
    if (isset($sp['error'])) {
        echo "Service Layer fetch failed for special prices: " . $sp['error'] . " — using local JSON fallback.\n";
        $data_sp = read_local_json($LOCAL_JSON_SP);
    } else {
        $data_sp = $sp;
        echo "Successfully fetched " . $sp['total_fetched'] . " special prices in " . $sp['pages'] . " pages.\n";
    }
}

$price_lists = [];
$special_prices = [];
$item_prices = [];

if (is_array($data_pl) && array_key_exists('value', $data_pl) && is_array($data_pl['value'])) {
    $price_lists = $data_pl['value'];
}
if (is_array($data_sp) && array_key_exists('value', $data_sp) && is_array($data_sp['value'])) {
    $special_prices = $data_sp['value'];
}
if (isset($data_ip) && is_array($data_ip) && array_key_exists('value', $data_ip) && is_array($data_ip['value'])) {
    $item_prices = $data_ip['value'];
}

// Insert into local DB
if ($db_conn && (count($price_lists) > 0 || count($special_prices) > 0 || count($item_prices) > 0)) {
    if (count($price_lists) > 0) {
        $db_conn->query("TRUNCATE TABLE price_lists");
        $stmt_pl = $db_conn->prepare("INSERT INTO price_lists (price_list_code, price_list_name, base_price_list, is_gross_price) VALUES (?, ?, ?, ?)");
        foreach ($price_lists as $row) {
            $code = intval($row['PriceListNo'] ?? 0);
            $name = $row['PriceListName'] ?? '';
            $base = intval($row['BasePriceList'] ?? 0);
            $gross = ($row['IsGrossPrice'] ?? '') === 'tYES' ? 'Y' : 'N';
            $stmt_pl->bind_param("isss", $code, $name, $base, $gross);
            $stmt_pl->execute();
        }
        $stmt_pl->close();
        echo "Inserted " . count($price_lists) . " records into price_lists table.\n";
    }

    // Process comprehensive item prices
    if (count($item_prices) > 0) {
        $db_conn->query("TRUNCATE TABLE item_prices");
        $stmt_ip = $db_conn->prepare("INSERT INTO item_prices (item_code, item_name, price_list_code, price, currency) VALUES (?, ?, ?, ?, ?)");
        $total_prices = 0;
        
        foreach ($item_prices as $item) {
            $item_code = $item['ItemCode'] ?? '';
            $item_name = $item['ItemName'] ?? '';
            
            // Process ItemPrices array - each item has an array of prices for different price lists
            if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                foreach ($item['ItemPrices'] as $price_data) {
                    $price_list_code = intval($price_data['PriceList'] ?? 0);
                    $price = floatval($price_data['Price'] ?? 0);
                    $currency = $price_data['Currency'] ?? '';
                    
                    // Insert all prices including 0 values
                    $stmt_ip->bind_param("ssids", $item_code, $item_name, $price_list_code, $price, $currency);
                    $stmt_ip->execute();
                    $total_prices++;
                }
            }
        }
        $stmt_ip->close();
        echo "Inserted $total_prices comprehensive item price records into item_prices table.\n";
    }

    if (count($special_prices) > 0) {
        $db_conn->query("TRUNCATE TABLE special_prices");
        $stmt_sp = $db_conn->prepare("INSERT INTO special_prices (item_code, price_list_code, price, currency, discount_percent) VALUES (?, ?, ?, ?, ?)");
        foreach ($special_prices as $row) {
            $item_code = $row['ItemCode'] ?? '';
            $price_list_code = intval($row['PriceListNum'] ?? 0);
            $price = floatval($row['Price'] ?? 0);
            $currency = $row['Currency'] ?? '';
            $discount = 0; // Standard prices have no discount
            $stmt_sp->bind_param("sidss", $item_code, $price_list_code, $price, $currency, $discount);
            $stmt_sp->execute();
        }
        $stmt_sp->close();
        echo "Inserted " . count($special_prices) . " records into special_prices table.\n";
    }
}

$db_conn->close();

echo "Sync completed. Fetched " . count($price_lists) . " price lists, " . count($item_prices) . " items with comprehensive prices, and " . count($special_prices) . " special prices.\n";
?>