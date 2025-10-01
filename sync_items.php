<?php
set_time_limit(1800);
@ini_set('max_execution_time', '1800');

// SAP B1 Service Layer config
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

// Create tables if not exist
$table_sql_items = "CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) UNIQUE,
    item_name VARCHAR(255),
    valid_for VARCHAR(10),
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($db_conn->query($table_sql_items) === TRUE) {
    error_log("DEBUG: Table items created or already exists");
} else {
    error_log("DEBUG: Error creating items table: " . $db_conn->error);
}

$table_sql_pricelists = "CREATE TABLE IF NOT EXISTS price_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    price_list_code INT UNIQUE,
    price_list_name VARCHAR(255),
    base_price_list INT,
    is_gross_price VARCHAR(10),
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($db_conn->query($table_sql_pricelists) === TRUE) {
    error_log("DEBUG: Table price_lists created or already exists");
} else {
    error_log("DEBUG: Error creating price_lists table: " . $db_conn->error);
}

// Create item_prices table for storing actual pricing data
$table_sql_item_prices = "CREATE TABLE IF NOT EXISTS item_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50),
    price_list_code INT,
    price DECIMAL(15,4),
    currency VARCHAR(10),
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_item_pricelist (item_code, price_list_code)
)";
if ($db_conn->query($table_sql_item_prices) === TRUE) {
    error_log("DEBUG: Table item_prices created or already exists");
} else {
    error_log("DEBUG: Error creating item_prices table: " . $db_conn->error);
}

function sl_login($slUrl, $username, $password, $companyDB, $cookieFile) {
    $loginUrl = rtrim($slUrl, '/') . '/Login';
    $payload = json_encode([
        'UserName'  => $username,
        'Password'  => $password,
        'CompanyDB' => $companyDB
    ]);

    error_log("DEBUG: SL Login attempt to $loginUrl with CompanyDB: $companyDB");
    error_log("DEBUG: Cookie file: $cookieFile");
    error_log("DEBUG: Cookie file exists: " . (file_exists($cookieFile) ? 'YES' : 'NO'));

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
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_VERBOSE        => false
    ]);
    
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    error_log("DEBUG: SL Login response HTTP: $http, Response: " . substr($resp, 0, 500));
    if ($curl_error) {
        error_log("DEBUG: SL Login cURL Error: $curl_error");
    }
    
    if ($resp === false || $http < 200 || $http >= 300) {
        error_log("DEBUG: SL Login failed - HTTP: $http, Response: " . substr($resp, 0, 200));
        return false;
    }
    
    $json = json_decode($resp, true);
    if ($json && isset($json['SessionId'])) {
        error_log("DEBUG: SL Login success, SessionId: " . $json['SessionId']);
    } else {
        error_log("DEBUG: SL Login response parsing failed");
    }
    
    return $json ?: false;
}

// Fetch items equivalent to: SELECT ItemCode, ItemName, validFor FROM OITM WHERE validFor ='Y'
function sl_get_valid_items($slUrl, $cookieFile) {
    $filter = "Valid eq 'tYES'";
    $select = '$select=ItemCode,ItemName,Valid';
    $top    = 20;
    $skip   = 0;
    $all    = [];

    error_log("DEBUG: Starting item fetch with filter: $filter");

    do {
        $url = rtrim($slUrl, '/') . '/Items?$filter=' . rawurlencode($filter) . '&' . $select . '&$count=true&$top=' . $top . '&$skip=' . $skip;
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
        $curl_error = curl_error($ch);
        curl_close($ch);

        error_log("DEBUG: Items URL: $url");
        error_log("DEBUG: Items HTTP Code: $http");
        if ($curl_error) {
            error_log("DEBUG: Items cURL Error: $curl_error");
        }
        error_log("DEBUG: Items Response length: " . strlen($resp));

        if ($resp === false || $http < 200 || $http >= 300) {
            error_log("DEBUG: Items API Error - HTTP $http: " . substr($resp, 0, 500));
            return ['value' => [], 'error' => 'HTTP ' . $http . ' - Response: ' . substr($resp, 0, 200)];
        }
        
        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) {
            error_log("DEBUG: Items Invalid JSON: " . json_last_error_msg());
            return ['value' => []];
        }
        
        $items = $json['value'];
        $all = array_merge($all, $items);
        $skip += $top;
        error_log("DEBUG: Fetched " . count($items) . " items in this page, total so far: " . count($all));
        
        // Log first few items for debugging
        if ($skip == $top && count($items) > 0) {
            error_log("DEBUG: Sample items from first page:");
            for ($i = 0; $i < min(3, count($items)); $i++) {
                error_log("DEBUG: Item " . ($i+1) . ": " . ($items[$i]['ItemCode'] ?? 'N/A') . " - " . ($items[$i]['ItemName'] ?? 'N/A'));
            }
        }
        
    } while (count($items) == $top);

    error_log("DEBUG: Total fetched items: " . count($all));

    return ['value' => $all];
}

// NEW: Fetch item prices from SpecialPrices table
function sl_get_item_prices($slUrl, $cookieFile) {
    $select = '$select=ItemCode,PriceListNum,Price,Currency';
    $top = 100;
    $skip = 0;
    $all = [];
    $maxPages = 50;
    $pages = 0;

    error_log("DEBUG: Starting item prices fetch");

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
            CURLOPT_TIMEOUT => 60
        ]);
        
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        error_log("DEBUG: SpecialPrices Page $pages URL: $url");
        error_log("DEBUG: SpecialPrices HTTP Code: $http");
        if ($curl_error) {
            error_log("DEBUG: SpecialPrices cURL Error: $curl_error");
        }

        if ($resp === false || $http < 200 || $http >= 300) {
            error_log("DEBUG: SpecialPrices API Error - HTTP $http: " . substr($resp, 0, 500));
            return ['value' => $all, 'error' => 'HTTP ' . $http . ' - Response: ' . substr($resp, 0, 200)];
        }
        
        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) {
            error_log("DEBUG: SpecialPrices Invalid JSON: " . json_last_error_msg());
            return ['value' => $all, 'error' => 'Invalid JSON response'];
        }
        
        $prices = $json['value'];
        $all = array_merge($all, $prices);
        $skip += $top;
        
        error_log("DEBUG: Fetched " . count($prices) . " item prices on page $pages, total so far: " . count($all));
        
        // Log sample prices for debugging
        if ($pages == 1 && count($prices) > 0) {
            error_log("DEBUG: Sample item prices:");
            for ($i = 0; $i < min(3, count($prices)); $i++) {
                $price = $prices[$i];
                error_log("DEBUG: Price " . ($i+1) . ": Item=" . ($price['ItemCode'] ?? 'N/A') .
                         ", PriceList=" . ($price['PriceListNum'] ?? 'N/A') .
                         ", Price=" . ($price['Price'] ?? 'N/A'));
            }
        }
        
    } while (count($prices) == $top && $pages < $maxPages);

    error_log("DEBUG: Total fetched item prices: " . count($all) . " after $pages pages");
    return ['value' => $all, 'total_fetched' => count($all), 'pages' => $pages];
}

// Fetch price lists
function sl_get_price_lists($slUrl, $cookieFile) {
    $select = '$select=PriceListNo,PriceListName,BasePriceList,IsGrossPrice';
    $top = 100;
    $skip = 0;
    $maxPages = 100;
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
            CURLOPT_TIMEOUT => 60
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
        
        // Continue if we got full page
        $hasMore = ($fetchedThisPage == $top);
        
    } while ($hasMore && $pages < $maxPages && $fetchedThisPage > 0);

    error_log("DEBUG: Completed. Total fetched price lists: " . count($all) . " after $pages pages");
    return ['value' => $all, 'total_fetched' => count($all), 'pages' => $pages];
}

echo "Starting sync...\n";

$ok = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
if ($ok === false) {
    $items = [];
    $price_lists = [];
    $error = 'Service Layer login failed';
    echo "Service Layer login failed\n";
} else {
    echo "Login successful. Fetching items...\n";
    $res_items = sl_get_valid_items($SL_URL, $COOKIEFILE);
    $items = $res_items['value'] ?? [];
    $items_error = $res_items['error'] ?? '';
    
    echo "Fetching price lists...\n";
    $res_pricelists = sl_get_price_lists($SL_URL, $COOKIEFILE);
    if (isset($res_pricelists['error'])) {
        echo "Error fetching price lists: " . $res_pricelists['error'] . "\n";
        $price_lists = [];
        $pricelists_error = $res_pricelists['error'];
    } else {
        $price_lists = $res_pricelists['value'] ?? [];
        echo "Successfully fetched " . $res_pricelists['total_fetched'] . " price lists in " . $res_pricelists['pages'] . " pages.\n";
        $pricelists_error = '';
    }
    
    echo "Fetching item prices...\n";
    $res_item_prices = sl_get_item_prices($SL_URL, $COOKIEFILE);
    if (isset($res_item_prices['error'])) {
        echo "Error fetching item prices: " . $res_item_prices['error'] . "\n";
        $item_prices = [];
        $item_prices_error = $res_item_prices['error'];
    } else {
        $item_prices = $res_item_prices['value'] ?? [];
        echo "Successfully fetched " . $res_item_prices['total_fetched'] . " item prices in " . $res_item_prices['pages'] . " pages.\n";
        $item_prices_error = '';
    }

    // Insert items into database
    error_log("DEBUG: Items array count: " . count($items));
    error_log("DEBUG: Items array empty check: " . (empty($items) ? 'EMPTY' : 'NOT EMPTY'));
    
    if (!empty($items)) {
        error_log("DEBUG: Starting items insertion process");
        $insert_count = 0;
        $error_count = 0;
        
        // Log first few items for debugging
        for ($i = 0; $i < min(3, count($items)); $i++) {
            $item = $items[$i];
            error_log("DEBUG: Sample item " . ($i+1) . ": " . json_encode($item));
        }
        
        foreach ($items as $item) {
            $item_code = $db_conn->real_escape_string($item['ItemCode'] ?? '');
            $item_name = $db_conn->real_escape_string($item['ItemName'] ?? '');
            $valid_for = ($item['Valid'] ?? '') === 'tYES' ? 'Y' : 'N';

            if (empty($item_code)) {
                error_log("DEBUG: Skipping item with empty ItemCode: " . json_encode($item));
                continue;
            }

            $insert_sql = "INSERT INTO items (item_code, item_name, valid_for)
                           VALUES ('$item_code', '$item_name', '$valid_for')
                           ON DUPLICATE KEY UPDATE item_name='$item_name', valid_for='$valid_for', fetched_at=CURRENT_TIMESTAMP";
            
            if ($db_conn->query($insert_sql) === TRUE) {
                $insert_count++;
                if ($insert_count <= 3) {
                    error_log("DEBUG: Successfully inserted item: $item_code - $item_name");
                }
            } else {
                $error_count++;
                error_log("DEBUG: Error inserting item $item_code: " . $db_conn->error);
                if ($error_count <= 3) {
                    error_log("DEBUG: Failed SQL: $insert_sql");
                }
            }
        }
        error_log("DEBUG: Inserted/updated $insert_count items into database, $error_count errors");
        echo "Inserted/updated $insert_count items into database.\n";
    } else {
        error_log("DEBUG: No items to insert - items array is empty");
        echo "No items to insert.\n";
    }
    
    // Insert price lists into database
    if (!empty($price_lists)) {
        $db_conn->query("TRUNCATE TABLE price_lists");
        $stmt_pl = $db_conn->prepare("INSERT INTO price_lists (price_list_code, price_list_name, base_price_list, is_gross_price) VALUES (?, ?, ?, ?)");
        $pl_insert_count = 0;
        
        foreach ($price_lists as $row) {
            $code = intval($row['PriceListNo'] ?? 0);
            $name = $row['PriceListName'] ?? '';
            $base = intval($row['BasePriceList'] ?? 0);
            $gross = ($row['IsGrossPrice'] ?? '') === 'tYES' ? 'Y' : 'N';
            $stmt_pl->bind_param("isss", $code, $name, $base, $gross);
            if ($stmt_pl->execute()) {
                $pl_insert_count++;
            }
        }
        $stmt_pl->close();
        echo "Inserted $pl_insert_count price lists into database.\n";
        error_log("DEBUG: Inserted $pl_insert_count price lists into database");
    } else {
        echo "No price lists to insert.\n";
        error_log("DEBUG: No price lists to insert");
    }
    
    // Insert item prices into database
    if (!empty($item_prices)) {
        $db_conn->query("TRUNCATE TABLE item_prices");
        $stmt_ip = $db_conn->prepare("INSERT INTO item_prices (item_code, price_list_code, price, currency) VALUES (?, ?, ?, ?)");
        $ip_insert_count = 0;
        
        foreach ($item_prices as $price_row) {
            $item_code = $price_row['ItemCode'] ?? '';
            $price_list = intval($price_row['PriceListNum'] ?? 0);
            $price = floatval($price_row['Price'] ?? 0);
            $currency = $price_row['Currency'] ?? '';
            
            $stmt_ip->bind_param("sids", $item_code, $price_list, $price, $currency);
            if ($stmt_ip->execute()) {
                $ip_insert_count++;
            }
        }
        $stmt_ip->close();
        echo "Inserted $ip_insert_count item prices into database.\n";
        error_log("DEBUG: Inserted $ip_insert_count item prices into database");
    } else {
        echo "No item prices to insert.\n";
        error_log("DEBUG: No item prices to insert");
    }
    
    $error = $items_error . ($pricelists_error ? " | PriceLists: $pricelists_error" : '') . ($item_prices_error ? " | ItemPrices: $item_prices_error" : '');
}

$db_conn->close();

echo "Sync completed. Fetched " . count($items) . " items, " . count($price_lists) . " price lists, and " . count($item_prices ?? []) . " item prices.\n";
if (!empty($error)) {
    echo "Error: $error\n";
}
?>