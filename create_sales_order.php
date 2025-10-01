<?php
session_start();
// create_sales_order_sap.php
// Full updated file: uses SAP Business One Service Layer to fetch Items and BusinessPartners
// and posts Sales Orders to SAP. Falls back to local DB when SAP fetch fails.

// ----------------- Configuration -----------------
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

// SAP B1 Service Layer config (fill with your environment values)
$SAP_SERVICE_LAYER_URL = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$SAP_COMPANY_DB = 'TESTI_MULT_310825';
$SAP_USERNAME = 'CLOUDTAKTIKS\\CTC100041.4';
$SAP_PASSWORD = 'A2r@h@R001';
$SAP_DEFAULT_WAREHOUSE = '01'; // default warehouse for items
$SAP_DEFAULT_SALES_EMPLOYEE_CODE = null; // optional
$SAP_DEFAULT_BPL_ID = null; // optional

// Simple cache settings (seconds)
$CACHE_DIR = __DIR__ . '/cache';
$CACHE_TTL = 300; // 5 minutes default; adjust as needed

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0755, true);
}

// ----------------- SAP Service Layer helpers -----------------
function sap_sl_login($baseUrl, $companyDb, $username, $password, &$cookies, &$error) {
    $cookies = '';
    $error = '';
    $url = rtrim($baseUrl, '/') . '/Login';
    $payload = json_encode([
        'CompanyDB' => $companyDb,
        'UserName' => $username,
        'Password' => $password
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = 'Curl error: ' . curl_error($ch);
        curl_close($ch);
        return false;
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = 'Login failed: HTTP ' . $httpCode . ' ' . $body;
        return false;
    }
    // Extract cookies from Set-Cookie headers
    $cookieMatches = [];
    preg_match_all('/^Set-Cookie:\s*([^;]+);/mi', $headers, $cookieMatches);
    if (!empty($cookieMatches[1])) {
        $cookies = implode('; ', $cookieMatches[1]);
    }
    return true;
}

function sap_sl_request($baseUrl, $method, $path, $payloadArrayOrNull, $cookies, &$error, &$decodedResponse) {
    $error = '';
    $decodedResponse = null;

    // allow $path to be a full URL returned by @odata.nextLink
    if (preg_match('/^https?:\/\//i', $path)) {
        $url = $path;
    } else {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if (!empty($cookies)) { $headers[] = 'Cookie: ' . $cookies; }
    $payload = $payloadArrayOrNull !== null ? json_encode($payloadArrayOrNull) : null;
    $opts = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    if ($payload !== null) { $opts[CURLOPT_POSTFIELDS] = $payload; }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = 'Curl error: ' . curl_error($ch);
        curl_close($ch);
        return false;
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = 'HTTP ' . $httpCode . ' ' . $response;
        return false;
    }
    $decoded = json_decode($response, true);
    $decodedResponse = $decoded !== null ? $decoded : $response;
    return true;
}

function sap_sl_logout($baseUrl, $cookies) {
    $err = '';
    $resp = null;
    @sap_sl_request($baseUrl, 'POST', '/Logout', null, $cookies, $err, $resp);
}

function sap_post_sales_order($baseUrl, $companyDb, $username, $password, $cardCode, $lines, $salesEmployeeCode, $bplId, &$docNum, &$error, $numAtCard = null) {
    $docNum = null;
    $error = '';
    $cookies = '';
    if (!sap_sl_login($baseUrl, $companyDb, $username, $password, $cookies, $error)) {
        return false;
    }
    $today = date('Y-m-d');
    $payload = [
        'CardCode' => $cardCode,
        'DocumentLines' => array_map(function($l) {
            // ensure correct types
            return [
                'ItemCode' => (string)$l['ItemCode'],
                'Quantity' => (float)$l['Quantity'],
                'WarehouseCode' => (string)$l['WarehouseCode']
            ];
        }, $lines),
        'DocDate' => $today,
        'DocDueDate' => $today,
        'TaxDate' => $today,
    ];
    if ($numAtCard !== null) { $payload['NumAtCard'] = (string)$numAtCard; }
    if (!empty($salesEmployeeCode)) { $payload['SalesPersonCode'] = (int)$salesEmployeeCode; }
    if (!empty($bplId)) { $payload['BPL_IDAssignedToInvoice'] = (int)$bplId; }
    $resp = null;
    $ok = sap_sl_request($baseUrl, 'POST', '/Orders', $payload, $cookies, $error, $resp);
    // Logout regardless
    sap_sl_logout($baseUrl, $cookies);
    if (!$ok) { return false; }
    if (is_array($resp)) {
        if (isset($resp['DocNum'])) { $docNum = $resp['DocNum']; }
        elseif (isset($resp['DocEntry'])) { $docNum = $resp['DocEntry']; }
    }
    return true;
}

// ----------------- Fetch & Caching helpers for Items/Customers -----------------
function cache_read($path, $ttl) {
    if (!file_exists($path)) return null;
    $stat = stat($path);
    if ($stat === false) return null;
    if (time() - $stat['mtime'] > $ttl) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : null;
}
function cache_write($path, $data) {
    @file_put_contents($path, json_encode($data));
}

function sap_fetch_items($baseUrl, $companyDb, $username, $password, &$items, &$error, $select = 'ItemCode,ItemName', $top = 200, $maxPages = 50) {
    $items = [];
    $error = '';
    error_log('DEBUG: sap_fetch_items called with select: ' . $select);
    $cookies = '';
    if (!sap_sl_login($baseUrl, $companyDb, $username, $password, $cookies, $error)) {
        return false;
    }
    $path = "/Items?\$select={$select}&\$top={$top}";
    $pages = 0;
    while ($path && $pages < $maxPages) {
        $pages++;
        $resp = null;
        if (!sap_sl_request($baseUrl, 'GET', $path, null, $cookies, $err, $resp)) {
            $error = "Failed fetching items: " . $err;
            sap_sl_logout($baseUrl, $cookies);
            return false;
        }
        if (is_array($resp) && isset($resp['value']) && is_array($resp['value'])) {
            foreach ($resp['value'] as $it) {
                $item = [
                    'item_code' => isset($it['ItemCode']) ? $it['ItemCode'] : '',
                    'item_name' => isset($it['ItemName']) ? $it['ItemName'] : '',
                ];
                error_log('DEBUG: Fetched item ' . $item['item_code'] . ' without price data');
                $items[] = $item;
            }
        }
        if (is_array($resp) && isset($resp['@odata.nextLink']) && $resp['@odata.nextLink']) {
            $path = $resp['@odata.nextLink'];
        } else {
            $path = null;
        }
    }
    sap_sl_logout($baseUrl, $cookies);
    error_log('DEBUG: Total items fetched: ' . count($items) . ', but no price list master data retrieved');
    return true;
}

function sap_fetch_business_partners($baseUrl, $companyDb, $username, $password, &$customers, &$error, $select = "CardCode,CardName", $top = 200, $maxPages = 50) {
    $customers = [];
    $error = '';
    $cookies = '';
    if (!sap_sl_login($baseUrl, $companyDb, $username, $password, $cookies, $error)) {
        return false;
    }
    // filter to customers only (CardType eq 'C')
    $path = "/BusinessPartners?\$select={$select}&\$filter=CardType%20eq%20'C'&\$top={$top}";
    $pages = 0;
    while ($path && $pages < $maxPages) {
        $pages++;
        $resp = null;
        if (!sap_sl_request($baseUrl, 'GET', $path, null, $cookies, $err, $resp)) {
            $error = "Failed fetching business partners: " . $err;
            sap_sl_logout($baseUrl, $cookies);
            return false;
        }
        if (is_array($resp) && isset($resp['value']) && is_array($resp['value'])) {
            foreach ($resp['value'] as $bp) {
                $customers[] = [
                    'card_code' => isset($bp['CardCode']) ? $bp['CardCode'] : '',
                    'card_name' => isset($bp['CardName']) ? $bp['CardName'] : '',
                ];
            }
        }
        if (is_array($resp) && isset($resp['@odata.nextLink']) && $resp['@odata.nextLink']) {
            $path = $resp['@odata.nextLink'];
        } else {
            $path = null;
        }
    }
    sap_sl_logout($baseUrl, $cookies);
    return true;
}

function get_items_cached($baseUrl, $companyDb, $username, $password, $cachePath, $ttl, &$items, &$error) {
    $items = cache_read($cachePath, $ttl);
    if ($items !== null) { $error = ''; return true; }
    if (!sap_fetch_items($baseUrl, $companyDb, $username, $password, $items, $error)) {
        return false;
    }
    cache_write($cachePath, $items);
    return true;
}
function get_customers_cached($baseUrl, $companyDb, $username, $password, $cachePath, $ttl, &$customers, &$error) {
    $customers = cache_read($cachePath, $ttl);
    if ($customers !== null) { $error = ''; return true; }
    if (!sap_fetch_business_partners($baseUrl, $companyDb, $username, $password, $customers, $error)) {
        return false;
    }
    cache_write($cachePath, $customers);
    return true;
}

// ----------------- Database connection -----------------
$db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db_conn->connect_error) {
    die("Database connection failed: " . $db_conn->connect_error);
}

// Create single Sales_order table (flat structure) if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS Sales_order (
    sales_order_id INT NOT NULL,
    cust VARCHAR(255),
    card_code VARCHAR(50),
    item_code VARCHAR(50),
    quantity INT,
    posting_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$db_conn->query($table_sql);

// Flash message from session
$message = null;
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// ----------------- Handle form submission -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   error_log('POST data: ' . json_encode($_POST));
   $card_code = $db_conn->real_escape_string($_POST['card_code'] ?? '');

    $item_codes = $_POST['item_code'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    if (!is_array($item_codes)) { $item_codes = [$item_codes]; }
    if (!is_array($quantities)) { $quantities = [$quantities]; }

    $successful_inserts = 0;
    $errors = [];

    if ($card_code && count($item_codes) > 0) {
        // Determine customer name (cust) for the selected card_code from local DB as quick lookup
        $cust_name = '';
        if ($resName = $db_conn->query("SELECT card_name FROM active_customers WHERE card_code='" . $db_conn->real_escape_string($card_code) . "' LIMIT 1")) {
            if ($rowName = $resName->fetch_assoc()) { $cust_name = $rowName['card_name']; }
            $resName->free();
        }

        // Generate a group sales_order_id for this order (max + 1)
        $next_id = 1;
        if ($resMax = $db_conn->query("SELECT MAX(sales_order_id) AS max_id FROM Sales_order")) {
            if ($rowMax = $resMax->fetch_assoc()) { $next_id = intval($rowMax['max_id']) + 1; }
            $resMax->free();
        }
        $sales_order_id = $next_id;

        $sap_lines = [];
        foreach ($item_codes as $index => $code) {
            $code_safe = $db_conn->real_escape_string(trim($code ?? ''));
            $qty_val = intval($quantities[$index] ?? 0);
            if ($code_safe !== '' && $qty_val > 0) {
                $insert_sql = "INSERT INTO Sales_order (sales_order_id, cust, card_code, item_code, quantity) VALUES ($sales_order_id, '" . $db_conn->real_escape_string($cust_name) . "', '$card_code', '$code_safe', $qty_val)";
                if ($db_conn->query($insert_sql) === TRUE) {
                    $successful_inserts++;
                    error_log('DEBUG: Sales order line added for item ' . $code_safe . ' qty ' . $qty_val . ' - no price data included');
                    // Prepare SAP line
                    $sap_lines[] = [
                        'ItemCode' => $code_safe,
                        'Quantity' => $qty_val,
                        'WarehouseCode' => $SAP_DEFAULT_WAREHOUSE
                    ];
                } else {
                    $errors[] = "Error inserting item \$code_safe: " . $db_conn->error;
                }
            }
        }

        if ($successful_inserts > 0 && empty($errors)) {
            $message = "Sales order #\$sales_order_id created (" . $successful_inserts . " item(s)).";
            // Attempt to post to SAP
            $sap_result_msg = '';
            if (!empty($sap_lines)) {
                $sap_err = '';
                $sap_docnum = null;
                if (sap_post_sales_order($SAP_SERVICE_LAYER_URL, $SAP_COMPANY_DB, $SAP_USERNAME, $SAP_PASSWORD, $card_code, $sap_lines, $SAP_DEFAULT_SALES_EMPLOYEE_CODE, $SAP_DEFAULT_BPL_ID, $sap_docnum, $sap_err, $sales_order_id)) {
                    $sap_result_msg = " Posted to SAP (DocNum: " . htmlspecialchars((string)$sap_docnum) . ").";
                } else {
                    $sap_result_msg = " Failed to post to SAP: " . htmlspecialchars($sap_err) . ".";
                }
                $message .= $sap_result_msg;
            }
        } elseif ($successful_inserts > 0 && !empty($errors)) {
            $message = "Sales order #\$sales_order_id partially created (" . $successful_inserts . " item(s)). " . implode(' ', $errors);
        } else {
            $message = "Please add at least one valid item with quantity > 0.";
        }
    } else {
        $message = "Please select a customer and add items.";
    }

    // Store message in session and redirect to prevent resubmission
    $_SESSION['message'] = $message;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ----------------- Fetch customers and items from local DB -----------------
$customers = [];
$items = [];

$result = $db_conn->query("SELECT card_code, card_name FROM active_customers ORDER BY card_name");
if ($result) {
    while ($row = $result->fetch_assoc()) { $customers[] = ['card_code'=>$row['card_code'], 'card_name'=>$row['card_name']]; }
    $result->free();
}

$result = $db_conn->query("SELECT item_code, item_name FROM items ORDER BY item_name");
if ($result) {
    while ($row = $result->fetch_assoc()) { $items[] = ['item_code'=>$row['item_code'], 'item_name'=>$row['item_name']]; }
    $result->free();
}

// Sales orders table removed - no longer displaying historical orders

$db_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Sales Order (SAP)</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Font Awesome for additional icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom SAP-style enhancements for Bootstrap 5 */
        :root {
            --sap-blue: #2c5aa0;
            --sap-light-blue: #e8f4fd;
            --sap-border-blue: #b8d4f0;
            --sap-gray: #f8f9fa;
            --sap-dark-gray: #6c757d;
        }
        
        body { 
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }
        
        .sap-container { 
            max-width: 95vw; 
            margin: 10px auto; 
            background: white; 
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            overflow: hidden;
            min-height: calc(100vh - 20px);
        }
        
        .sap-header { 
            background: linear-gradient(135deg, var(--sap-blue), #1e3d73); 
            color: white; 
            padding: 20px 30px; 
            font-weight: 600; 
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .customer-section { 
            background: linear-gradient(135deg, var(--sap-gray), #ffffff); 
            padding: 20px 25px; 
            border-bottom: 2px solid #dee2e6; 
        }
        
        .customer-search-grid {
            display: grid;
            grid-template-columns: 1fr 2fr 1fr;
            gap: 20px;
            align-items: end;
            margin-top: 15px;
        }
        
        .customer-search-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .customer-search-field label {
            font-weight: 600;
            color: #495057;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .customer-search-field .form-control {
            font-size: 14px;
            border-radius: 6px;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .customer-search-field .form-control:focus {
            border-color: var(--sap-blue);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
        }
        
        .customer-info-display {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 35px;
            margin-top: 25px;
        }
        
        .customer-details, .order-details {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .customer-details h6, .order-details h6 {
            color: var(--sap-blue);
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .detail-field {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            padding: 8px 0;
        }
        
        .detail-field label {
            font-weight: 600;
            color: #6c757d;
            min-width: 120px;
            margin-right: 15px;
            font-size: 13px;
        }
        
        .detail-field .form-control, .detail-field span {
            font-size: 14px;
            flex: 1;
        }
        
        /* Items table styling - Full desktop landscape optimized */
        .items-section { 
            background: white; 
            border-top: 2px solid #dee2e6;
            padding: 0;
            margin: 0;
        }
        
        .items-table-container {
            padding: 20px 25px;
        }
        
        .items-table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--sap-blue);
        }
        
        .items-table-header h5 {
            color: var(--sap-blue);
            font-weight: 600;
            margin: 0;
        }
        
        .items-entry-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .items-entry-table thead th {
            background: var(--sap-blue);
            color: white;
            padding: 15px 12px;
            font-size: 14px;
            font-weight: 600;
            text-align: left;
            border: none;
        }
        
        .items-entry-table tbody td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .items-entry-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .items-entry-table .form-control {
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .items-entry-table .form-control:focus {
            border-color: var(--sap-blue);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
            outline: none;
        }
        
        .items-entry-table .form-control[readonly] {
            background-color: #f8f9fa;
            border-color: #dee2e6;
        }
        
        /* Column widths for landscape optimization */
        .col-item-code { width: 20%; }
        .col-item-name { width: 50%; }
        .col-quantity { width: 30%; }
        
        /* Enhanced buttons */
        .btn-sap-primary {
            background: var(--sap-blue);
            border-color: var(--sap-blue);
            color: white;
            font-weight: 500;
            border-radius: 4px;
            padding: 8px 16px;
            font-size: 13px;
        }
        
        .btn-sap-primary:hover {
            background: #1e3d73;
            border-color: #1e3d73;
            color: white;
        }
        
        .btn-sap-secondary {
            background: var(--sap-gray);
            border-color: #dee2e6;
            color: #495057;
            font-weight: 500;
            border-radius: 4px;
            padding: 8px 16px;
            font-size: 13px;
        }
        
        .btn-sap-secondary:hover {
            background: #e9ecef;
            border-color: #adb5bd;
            color: #495057;
        }
        
        /* Footer styling - Bootstrap enhanced */
        .sap-footer { 
            background: var(--sap-gray); 
            padding: 15px 20px; 
            border-top: 2px solid var(--sap-blue); 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .footer-info {
            display: flex;
            gap: 25px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .footer-info span {
            font-size: 13px;
            color: #6c757d;
        }
        
        .footer-actions { 
            display: flex; 
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* Enhanced message styling */
        .alert-sap {
            border: none;
            border-left: 4px solid #ffc107;
            background: #fff3cd;
            color: #856404;
            border-radius: 0 6px 6px 0;
            margin: 15px 20px;
        }
        
        /* Responsive enhancements - Full desktop landscape optimized */
        @media (max-width: 1600px) {
            .sap-container {
                max-width: 98vw;
                margin: 5px auto;
            }
        }
        
        @media (max-width: 1400px) {
            .customer-search-grid { 
                grid-template-columns: 1fr 1.5fr 1fr; 
                gap: 15px;
            }
            
            .customer-info-display { 
                grid-template-columns: 1fr; 
                gap: 20px;
            }
        }
        
        @media (max-width: 1200px) {
            .customer-search-grid { 
                grid-template-columns: 1fr; 
                gap: 20px;
            }
            
            .compact-row.visible { 
                grid-template-columns: 1fr 1fr; 
                gap: 15px;
            }
            
            .compact-row .form-control { 
                width: 100%; 
            }
            
            .sap-footer {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
                gap: 20px;
            }
            
            .footer-info {
                justify-content: center;
                flex-wrap: wrap;
            }
        }
        
        @media (max-width: 768px) {
            .sap-container {
                margin: 10px;
                border-radius: 8px;
            }
            
            .sap-header {
                padding: 15px 20px;
                font-size: 18px;
            }
            
            .customer-section {
                padding: 20px;
            }
            
            .compact-row.visible { 
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .detail-field {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .detail-field label {
                min-width: auto;
                margin-right: 0;
            }
            
            .footer-info {
                flex-direction: column;
                gap: 15px;
            }
        }
        
        /* Animation for smooth transitions */
        .compact-row, .items-section {
            transition: all 0.3s ease-in-out;
        }
        
        /* Loading states */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* Success/Error states */
        .is-valid {
            border-color: #198754 !important;
            box-shadow: 0 0 0 2px rgba(25, 135, 84, 0.25) !important;
        }
        
        .is-invalid {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.25) !important;
        }
    </style>
</head>
<body>
    <div class="sap-container">
        <div class="sap-header">
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Sales Order</span>
            <small class="ms-auto opacity-75">SAP Business One Style</small>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-sap alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="post" id="sales-order-form">
            <!-- Customer Selection Section - Enhanced -->
            <div class="customer-section">
                <h5 class="mb-4">
                    <i class="bi bi-person-circle me-2"></i>
                    Customer Selection & Information
                </h5>
                
                <!-- Customer Search Grid -->
                <div class="customer-search-grid">
                    <div class="customer-search-field">
                        <label for="customer-card-code">
                            <i class="bi bi-hash me-1"></i>Card Code:
                        </label>
                        <input type="text" id="customer-card-code" name="card_code" class="form-control" 
                               placeholder="Enter card code (e.g., C00001)" autocomplete="off">
                        <input type="hidden" id="selected-card-code" name="card_code_hidden">
                    </div>
                    
                    <div class="customer-search-field">
                        <label for="customer-name-search">
                            <i class="bi bi-person me-1"></i>Customer Name:
                        </label>
                        <input type="text" id="customer-name-search" class="form-control" 
                               placeholder="Enter customer name or search..." autocomplete="off">
                        <div id="customer-suggestions" class="dropdown-menu w-100" style="display: none; max-height: 200px; overflow-y: auto;">
                            <!-- Customer suggestions will appear here -->
                        </div>
                    </div>
                    
                    <div class="customer-search-field">
                        <label>&nbsp;</label>
                        <button type="button" id="search-customer" class="btn btn-sap-primary w-100">
                            <i class="bi bi-search me-2"></i>Search Customer
                        </button>
                    </div>
                </div>
                
                <!-- Customer Information Display (visible but empty) -->
                <div class="customer-info-display" id="customer-info-display">
                    <div class="customer-details">
                        <h6>
                            <i class="bi bi-person-badge me-2"></i>Customer Details
                        </h6>
                        
                        <div class="detail-field">
                            <label>Name:</label>
                            <span id="customer-name" class="fw-semibold text-primary"></span>
                        </div>
                        
                        <div class="detail-field">
                            <label>Card Code:</label>
                            <span id="customer-card-display" class="fw-semibold"></span>
                        </div>
                        
                        <div class="detail-field">
                            <label>Contact Person:</label>
                            <span id="customer-contact"></span>
                        </div>
                    </div>
                    
                    <div class="order-details">
                        <h6>
                            <i class="bi bi-file-text me-2"></i>Order Details
                        </h6>
                        
                        <div class="detail-field">
                            <label>Order No.:</label>
                            <span id="order-number" class="fw-semibold text-primary">Auto-generated</span>
                        </div>
                        
                        <div class="detail-field">
                            <label>Status:</label>
                            <span class="badge bg-success fs-6">Open</span>
                        </div>
                        
                        <div class="detail-field">
                            <label for="posting-date">Posting Date:</label>
                            <input type="date" id="posting-date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="detail-field">
                            <label for="delivery-date">Delivery Date:</label>
                            <input type="date" id="delivery-date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        </div>
                        
                        <div class="detail-field">
                            <label for="document-date">Document Date:</label>
                            <input type="date" id="document-date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Entry Section (visible but empty) -->
            <div id="items-section" class="items-section">
                <div class="items-table-container">
                    <div class="items-table-header">
                        <h5>
                            <i class="bi bi-table me-2"></i>
                            Order Items Entry
                        </h5>
                    </div>
                    
                    <table class="items-entry-table">
                        <thead>
                            <tr>
                                <th class="col-item-code">
                                    <i class="bi bi-hash me-1"></i>Item Code
                                </th>
                                <th class="col-item-name">
                                    <i class="bi bi-box-seam me-1"></i>Item Name
                                </th>
                                <th class="col-quantity">
                                    <i class="bi bi-123 me-1"></i>Quantity
                                </th>
                            </tr>
                        </thead>
                        <tbody id="items-entry-tbody">
                            <!-- Item entry rows will be dynamically added here -->
                        </tbody>
                        <tbody id="extra-rows-section" class="collapse">
                            <!-- Additional rows will be added here -->
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="2" class="fw-bold text-end">
                                    <i class="bi bi-calculator me-1"></i>Total:
                                </td>
                                <td>
                                    <input type="number" id="total-qty" class="form-control form-control-sm fw-bold" value="0" readonly>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="mt-3 text-center">
                        <button type="button" id="expand-rows-btn" class="btn btn-sap-secondary" data-bs-toggle="collapse" data-bs-target="#extra-rows-section" style="display: none;">
                            <i class="bi bi-chevron-down me-1"></i>Show More Rows
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer with Find/Cancel buttons - Enhanced Bootstrap -->
            <div class="sap-footer">
                <div class="footer-info">
                    <span>
                        <i class="bi bi-person-badge me-1"></i>
                        <strong>Sales Employee:</strong> System User
                    </span>
                    <span>
                        <i class="bi bi-person-circle me-1"></i>
                        <strong>Owner:</strong> Admin
                    </span>
                </div>
                <div class="footer-actions">
                    <button type="button" id="find-btn" class="btn btn-sap-secondary">
                        <i class="bi bi-search me-1"></i>Find
                    </button>
                    <button type="button" id="cancel-btn" class="btn btn-sap-secondary">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" id="submit-btn" class="btn btn-sap-primary" style="display: none;">
                        <i class="bi bi-check-circle me-1"></i>Create Sales Order
                    </button>
                </div>
            </div>
        </form>
    </div>

    <datalist id="item-name-list">
        <?php foreach ($items as $item): ?>
            <option value="<?php echo htmlspecialchars($item['item_name']); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <datalist id="item-code-list">
        <?php foreach ($items as $item): ?>
            <option value="<?php echo htmlspecialchars($item['item_code']); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <template id="item-entry-row-template">
        <tr class="item-entry-row">
            <td>
                <input type="text" name="item_code[]" class="form-control item-code-input" list="item-code-list" 
                       placeholder="Enter item code" autocomplete="off">
            </td>
            <td>
                <input type="text" name="item_name[]" class="form-control item-name-input" list="item-name-list" 
                       placeholder="Enter item name" autocomplete="off">
            </td>
            <td>
                <input type="number" name="quantity[]" class="form-control quantity-input" min="1" value="" placeholder="Qty">
            </td>
        </tr>
    </template>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        (function() {
            // New customer search elements
            var customerCardCodeInput = document.getElementById('customer-card-code');
            var customerNameSearchInput = document.getElementById('customer-name-search');
            var selectedCardCodeInput = document.getElementById('selected-card-code');
            var searchCustomerBtn = document.getElementById('search-customer');
            var customerSuggestions = document.getElementById('customer-suggestions');
            var customerInfoDisplay = document.getElementById('customer-info-display');
            
            // Customer display elements
            var customerNameDisplay = document.getElementById('customer-name');
            var customerCardDisplay = document.getElementById('customer-card-display');
            var customerContactDisplay = document.getElementById('customer-contact');
            
            // Other elements
            var itemsSection = document.getElementById('items-section');
            var itemsEntryTbody = document.getElementById('items-entry-tbody');
            var extraRowsSection = document.getElementById('extra-rows-section');
            var itemEntryTemplate = document.getElementById('item-entry-row-template');
            var totalQtyInput = document.getElementById('total-qty');
            var expandRowsBtn = document.getElementById('expand-rows-btn');
            var submitBtn = document.getElementById('submit-btn');
            var findBtn = document.getElementById('find-btn');
            var cancelBtn = document.getElementById('cancel-btn');
            
            var items = <?php echo json_encode($items); ?>;
            var customers = <?php echo json_encode($customers); ?>;
            var currentCustomer = null;

            function findByName(name) {
                if (!name) return null;
                name = name.toLowerCase();
                for (var i = 0; i < items.length; i++) {
                    if ((items[i].item_name || '').toLowerCase() === name) return items[i];
                }
                return null;
            }

            function findByCode(code) {
                if (!code) return null;
                code = code.toLowerCase();
                for (var i = 0; i < items.length; i++) {
                    if ((items[i].item_code || '').toLowerCase() === code) return items[i];
                }
                return null;
            }

            function findCustomerByCode(code) {
                for (var i = 0; i < customers.length; i++) {
                    if (customers[i].card_code === code) return customers[i];
                }
                return null;
            }

            function findCustomersByName(name) {
                if (!name || name.length < 2) return [];
                name = name.toLowerCase();
                var matches = [];
                for (var i = 0; i < customers.length; i++) {
                    if (customers[i].card_name.toLowerCase().includes(name)) {
                        matches.push(customers[i]);
                    }
                }
                return matches.slice(0, 5); // Limit to 5 suggestions
            }

            function showCustomerSuggestions(matches) {
                if (matches.length === 0) {
                    customerSuggestions.style.display = 'none';
                    return;
                }

                var html = '';
                matches.forEach(function(customer) {
                    html += `
                        <div class="dropdown-item customer-suggestion" data-card-code="${customer.card_code}" data-card-name="${customer.card_name}">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <strong>${customer.card_name}</strong><br>
                                    <small class="text-muted">${customer.card_code}</small>
                                </div>
                                <i class="bi bi-arrow-right-circle text-primary"></i>
                            </div>
                        </div>
                    `;
                });
                
                customerSuggestions.innerHTML = html;
                customerSuggestions.style.display = 'block';
                
                // Add click handlers to suggestions
                customerSuggestions.querySelectorAll('.customer-suggestion').forEach(function(item) {
                    item.addEventListener('click', function() {
                        var cardCode = this.dataset.cardCode;
                        var cardName = this.dataset.cardName;
                        selectCustomer(cardCode, cardName);
                    });
                });
            }

            function selectCustomer(cardCode, cardName) {
                currentCustomer = findCustomerByCode(cardCode);
                if (currentCustomer) {
                    // Update inputs
                    customerCardCodeInput.value = cardCode;
                    customerNameSearchInput.value = cardName;
                    selectedCardCodeInput.value = cardCode;
                    
                    // Hide suggestions
                    customerSuggestions.style.display = 'none';
                    
                    // Show customer info and enable order creation
                    showCustomerDetails(currentCustomer);
                    
                    // Show success message
                    if (typeof showToast === 'function') {
                        showToast('Customer selected successfully!', 'success');
                    }
                }
            }

            function showCustomerDetails(customer) {
                customerNameDisplay.textContent = customer.card_name;
                customerCardDisplay.textContent = customer.card_code;
                customerContactDisplay.textContent = 'Contact Person';
                
                // Show submit button
                submitBtn.style.display = 'inline-block';
            }

            function hideCustomerDetails() {
                // Clear customer info
                customerNameDisplay.textContent = '';
                customerCardDisplay.textContent = '';
                customerContactDisplay.textContent = '';
                submitBtn.style.display = 'none';
                currentCustomer = null;
                
                // Clear inputs
                customerCardCodeInput.value = '';
                customerNameSearchInput.value = '';
                selectedCardCodeInput.value = '';
                customerSuggestions.style.display = 'none';
                
                updateTotals();
            }

            function updateTotals() {
                var allQtyInputs = document.querySelectorAll('input[name="quantity[]"]');
                var totalQty = 0;
                
                allQtyInputs.forEach(function(input) {
                    var val = parseInt(input.value, 10);
                    if (!isNaN(val) && val > 0) { totalQty += val; }
                });
                
                totalQtyInput.value = totalQty;

                // Check if main tbody has filled rows to show expand button
                var mainRows = itemsEntryTbody.querySelectorAll('tr');
                var hasFilledRow = Array.from(mainRows).some(function(row) {
                    var codeInput = row.querySelector('input[name="item_code[]"]');
                    return codeInput && codeInput.value.trim();
                });
                expandRowsBtn.style.display = hasFilledRow ? 'inline-block' : 'none';
            }

            function addEmptyItemRow(targetTbody = itemsEntryTbody) {
                var clone = document.importNode(itemEntryTemplate.content, true);
                var tr = clone.querySelector('tr');
                targetTbody.appendChild(tr);
                attachItemRowEvents(tr);
            }

            function attachItemRowEvents(row) {
                var itemCodeInput = row.querySelector('.item-code-input');
                var itemNameInput = row.querySelector('.item-name-input');
                var quantityInput = row.querySelector('.quantity-input');

                // Item code and name sync
                itemCodeInput.addEventListener('input', function() {
                    var code = this.value.trim();
                    if (code) {
                        var item = findByCode(code);
                        if (item) {
                            itemNameInput.value = item.item_name;
                        }
                    }
                    updateTotals();
                });

                itemNameInput.addEventListener('input', function() {
                    var name = this.value.trim();
                    if (name) {
                        var item = findByName(name);
                        if (item) {
                            itemCodeInput.value = item.item_code;
                        }
                    }
                    updateTotals();
                });

                quantityInput.addEventListener('input', function() {
                    updateTotals();
                });
            }

            // Customer search event handlers
            customerNameSearchInput.addEventListener('input', function() {
                var query = this.value.trim();
                if (query.length >= 2) {
                    var matches = findCustomersByName(query);
                    showCustomerSuggestions(matches);
                } else {
                    customerSuggestions.style.display = 'none';
                }
            });

            customerCardCodeInput.addEventListener('input', function() {
                var code = this.value.trim();
                if (code) {
                    var customer = findCustomerByCode(code);
                    if (customer) {
                        customerNameSearchInput.value = customer.card_name;
                        selectCustomer(customer.card_code, customer.card_name);
                    }
                }
            });

            searchCustomerBtn.addEventListener('click', function() {
                var cardCode = customerCardCodeInput.value.trim();
                var name = customerNameSearchInput.value.trim();
                
                if (cardCode) {
                    var customer = findCustomerByCode(cardCode);
                    if (customer) {
                        selectCustomer(customer.card_code, customer.card_name);
                    } else {
                        if (typeof showToast === 'function') {
                            showToast('Customer not found with this card code', 'warning');
                        }
                    }
                } else if (name) {
                    var matches = findCustomersByName(name);
                    if (matches.length === 1) {
                        selectCustomer(matches[0].card_code, matches[0].card_name);
                    } else if (matches.length > 1) {
                        showCustomerSuggestions(matches);
                        if (typeof showToast === 'function') {
                            showToast('Multiple customers found. Please select one from the list.', 'info');
                        }
                    } else {
                        if (typeof showToast === 'function') {
                            showToast('No customers found with this name', 'warning');
                        }
                    }
                } else {
                    if (typeof showToast === 'function') {
                        showToast('Please enter either card code or customer name', 'warning');
                    }
                }
            });

            // Hide suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!customerSuggestions.contains(e.target) && !customerNameSearchInput.contains(e.target)) {
                    customerSuggestions.style.display = 'none';
                }
            });

            // Toast notification function
            function showToast(message, type) {
                var toastHtml = `
                    <div class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                                ${message}
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                `;
                
                var toastContainer = document.getElementById('toast-container') || createToastContainer();
                toastContainer.insertAdjacentHTML('beforeend', toastHtml);
                
                var toastElement = toastContainer.lastElementChild;
                var toast = new bootstrap.Toast(toastElement);
                toast.show();
                
                // Remove toast element after it's hidden
                toastElement.addEventListener('hidden.bs.toast', function() {
                    this.remove();
                });
            }
            
            function createToastContainer() {
                var container = document.createElement('div');
                container.id = 'toast-container';
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                container.style.zIndex = '9999';
                document.body.appendChild(container);
                return container;
            }

            // Find button functionality
            findBtn.addEventListener('click', function() {
                showToast('Find functionality - search existing orders', 'info');
            });

            // Cancel button functionality
            cancelBtn.addEventListener('click', function() {
                var confirmModal = new bootstrap.Modal(document.getElementById('cancelConfirmModal'));
                confirmModal.show();
            });

            // Form validation enhancement
            function validateForm() {
                var isValid = true;
                var cardCode = selectedCardCodeInput.value;
                
                if (!cardCode || !currentCustomer) {
                    customerCardCodeInput.classList.add('is-invalid');
                    customerNameSearchInput.classList.add('is-invalid');
                    isValid = false;
                    if (typeof showToast === 'function') {
                        showToast('Please select a customer first', 'warning');
                    }
                } else {
                    customerCardCodeInput.classList.remove('is-invalid');
                    customerNameSearchInput.classList.remove('is-invalid');
                    customerCardCodeInput.classList.add('is-valid');
                    customerNameSearchInput.classList.add('is-valid');
                }
                
                // Check for at least one item with quantity > 0 across all rows
                var allQtyInputs = document.querySelectorAll('input[name="quantity[]"]');
                var hasValidItem = false;
                allQtyInputs.forEach(function(input) {
                    var codeInput = input.closest('tr').querySelector('input[name="item_code[]"]');
                    var qty = parseInt(input.value, 10);
                    if (codeInput && codeInput.value.trim() && !isNaN(qty) && qty > 0) {
                        hasValidItem = true;
                    }
                });
                
                if (!hasValidItem) {
                    if (typeof showToast === 'function') {
                        showToast('Please add at least one item with quantity > 0', 'warning');
                    }
                    isValid = false;
                }

                // Collapse unused extra rows on submit
                var extraRows = extraRowsSection.querySelectorAll('tr');
                var allExtraEmpty = Array.from(extraRows).every(function(row) {
                    var codeInput = row.querySelector('input[name="item_code[]"]');
                    var qtyInput = row.querySelector('input[name="quantity[]"]');
                    return !codeInput.value.trim() && !(parseInt(qtyInput.value) > 0);
                });
                if (allExtraEmpty && extraRowsSection.classList.contains('show')) {
                    var collapseInstance = bootstrap.Collapse.getInstance(extraRowsSection);
                    if (collapseInstance) {
                        collapseInstance.hide();
                    }
                }
                
                return isValid;
            }

            // Enhanced form submission
            document.getElementById('sales-order-form').addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    showToast('Please complete all required fields', 'danger');
                } else {
                    showToast('Creating sales order...', 'info');
                }
            });

            // Initialize - add 5 empty rows to main tbody
            for (var i = 0; i < 5; i++) {
                addEmptyItemRow();
            }
            updateTotals();
        })();
    </script>

    <!-- Cancel Confirmation Modal -->
    <div class="modal fade" id="cancelConfirmModal" tabindex="-1" aria-labelledby="cancelConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelConfirmModalLabel">
                        <i class="bi bi-exclamation-triangle text-warning me-2"></i>
                        Confirm Cancel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel? This will clear all data and you will lose any unsaved changes.</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle me-2"></i>
                        This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-arrow-left me-1"></i>Keep Editing
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmCancel">
                        <i class="bi bi-x-circle me-1"></i>Cancel Order
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle cancel confirmation
        document.getElementById('confirmCancel').addEventListener('click', function() {
            // Clear customer search inputs
            document.getElementById('customer-card-code').value = '';
            document.getElementById('customer-name-search').value = '';
            document.getElementById('selected-card-code').value = '';
            document.getElementById('customer-suggestions').style.display = 'none';
            
            // Clear customer details
            document.getElementById('customer-name').textContent = '';
            document.getElementById('customer-card-display').textContent = '';
            document.getElementById('customer-contact').textContent = '';
            document.getElementById('submit-btn').style.display = 'none';
            
            // Clear items table - both main and extra
            document.getElementById('items-entry-tbody').innerHTML = '';
            document.getElementById('extra-rows-section').innerHTML = '';
            document.getElementById('expand-rows-btn').style.display = 'none';
            
            // Re-add 5 empty rows to main tbody
            var itemsEntryTbody = document.getElementById('items-entry-tbody');
            var itemEntryTemplate = document.getElementById('item-entry-row-template');
            var items = <?php echo json_encode($items); ?>;
            for (var i = 0; i < 5; i++) {
                var clone = document.importNode(itemEntryTemplate.content, true);
                var tr = clone.querySelector('tr');
                itemsEntryTbody.appendChild(tr);
                attachItemRowEvents(tr); // Define attachItemRowEvents here or make global
            }
            
            // Update totals
            updateTotals(); // Assuming updateTotals is global or accessible
            
            // Close modal
            var modal = bootstrap.Modal.getInstance(document.getElementById('cancelConfirmModal'));
            modal.hide();
            
            // Show success message
            setTimeout(function() {
                if (typeof showToast === 'function') {
                    showToast('Order cancelled successfully', 'info');
                }
            }, 300);
        });

        // Make necessary functions global for access in cancel script
        function updateTotals() {
            var allQtyInputs = document.querySelectorAll('input[name="quantity[]"]');
            var totalQty = 0;
            
            allQtyInputs.forEach(function(input) {
                var val = parseInt(input.value, 10);
                if (!isNaN(val) && val > 0) { totalQty += val; }
            });
            
            document.getElementById('total-qty').value = totalQty;

            // Check if main tbody has filled rows to show expand button
            var mainRows = document.querySelectorAll('#items-entry-tbody tr');
            var hasFilledRow = Array.from(mainRows).some(function(row) {
                var codeInput = row.querySelector('input[name="item_code[]"]');
                return codeInput && codeInput.value.trim();
            });
            document.getElementById('expand-rows-btn').style.display = hasFilledRow ? 'inline-block' : 'none';
        }

        function attachItemRowEvents(row) {
            var itemCodeInput = row.querySelector('.item-code-input');
            var itemNameInput = row.querySelector('.item-name-input');
            var quantityInput = row.querySelector('.quantity-input');
            var items = <?php echo json_encode($items); ?>;

            function findByName(name) {
                if (!name) return null;
                name = name.toLowerCase();
                for (var i = 0; i < items.length; i++) {
                    if ((items[i].item_name || '').toLowerCase() === name) return items[i];
                }
                return null;
            }

            function findByCode(code) {
                if (!code) return null;
                code = code.toLowerCase();
                for (var i = 0; i < items.length; i++) {
                    if ((items[i].item_code || '').toLowerCase() === code) return items[i];
                }
                return null;
            }

            itemCodeInput.addEventListener('input', function() {
                var code = this.value.trim();
                if (code) {
                    var item = findByCode(code);
                    if (item) {
                        itemNameInput.value = item.item_name;
                    }
                }
                updateTotals();
            });

            itemNameInput.addEventListener('input', function() {
                var name = this.value.trim();
                if (name) {
                    var item = findByName(name);
                    if (item) {
                        itemCodeInput.value = item.item_code;
                    }
                }
                updateTotals();
            });

            quantityInput.addEventListener('input', function() {
                updateTotals();
            });
        }

        // Expand rows button handler
        document.getElementById('expand-rows-btn').addEventListener('click', function() {
            var extraTbody = document.getElementById('extra-rows-section');
            var itemEntryTemplate = document.getElementById('item-entry-row-template');
            for (var i = 0; i < 5; i++) {
                var clone = document.importNode(itemEntryTemplate.content, true);
                var tr = clone.querySelector('tr');
                extraTbody.appendChild(tr);
                attachItemRowEvents(tr);
            }
            updateTotals();
            // Optionally change button text or hide after first expand
            this.innerHTML = '<i class="bi bi-chevron-up me-1"></i>Hide Extra Rows';
            this.setAttribute('data-bs-toggle', 'collapse');
            this.setAttribute('data-bs-target', '#extra-rows-section');
        });

        // Handle collapse event to revert button
        document.getElementById('extra-rows-section').addEventListener('hidden.bs.collapse', function () {
            var btn = document.getElementById('expand-rows-btn');
            btn.innerHTML = '<i class="bi bi-chevron-down me-1"></i>Show More Rows';
        });
    </script>
</body>
</html>