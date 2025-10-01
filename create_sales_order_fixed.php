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

function sap_post_sales_order($baseUrl, $companyDb, $username, $password, $cardCode, $lines, $salesEmployeeCode, $bplId, &$docNum, &$error, $numAtCard = null, $docDate = null, $dueDate = null, $taxDate = null) {
    $docNum = null;
    $error = '';
    $cookies = '';
    if (!sap_sl_login($baseUrl, $companyDb, $username, $password, $cookies, $error)) {
        return false;
    }
    
    // Use provided dates or default to today
    $today = date('Y-m-d');
    $docDate = $docDate ?: $today;
    $dueDate = $dueDate ?: $today;
    $taxDate = $taxDate ?: $today;
    
    $payload = [
        'CardCode' => $cardCode,
        'DocumentLines' => array_map(function($l) {
            // ensure correct types and include price if available
            $line = [
                'ItemCode' => (string)$l['ItemCode'],
                'Quantity' => (float)$l['Quantity'],
                'WarehouseCode' => (string)$l['WarehouseCode']
            ];
            // Add price if provided
            if (isset($l['Price']) && $l['Price'] > 0) {
                $line['Price'] = (float)$l['Price'];
            }
            return $line;
        }, $lines),
        'DocDate' => $docDate,
        'DocDueDate' => $dueDate,
        'TaxDate' => $taxDate,
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

// ----------------- Price fetching functions -----------------
function get_item_price_from_db($db_conn, $item_code, $price_list_code = 1) {
    $item_code_safe = $db_conn->real_escape_string($item_code);
    $price_list_safe = intval($price_list_code);
    
    $query = "SELECT price, currency FROM item_prices
              WHERE item_code = '$item_code_safe' AND price_list_code = $price_list_safe
              LIMIT 1";
    
    $result = $db_conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $result->free();
        return [
            'price' => floatval($row['price']),
            'currency' => $row['currency']
        ];
    }
    return null;
}

function get_customer_price_list($db_conn, $card_code) {
    // Try to get customer's default price list from active_customers table
    $card_code_safe = $db_conn->real_escape_string($card_code);
    $query = "SELECT price_list FROM active_customers WHERE card_code = '$card_code_safe' LIMIT 1";

    $result = $db_conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $result->free();
        return intval($row['price_list']);
    }

    // Default to price list 1 (Retail Daresalam) if not found
    return 1;
}

// ----------------- AJAX Endpoint for Price Fetching -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_item_price') {
    header('Content-Type: application/json');
    
    $db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($db_conn->connect_error) {
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    
    $item_code = $_POST['item_code'] ?? '';
    $card_code = $_POST['card_code'] ?? '';
    $price_list_code = $_POST['price_list_code'] ?? null;
    
    if (!$item_code) {
        echo json_encode(['error' => 'Item code required']);
        exit;
    }
    
    // Determine price list to use
    if (!$price_list_code && $card_code) {
        $price_list_code = get_customer_price_list($db_conn, $card_code);
    } else if (!$price_list_code) {
        $price_list_code = 1; // Default
    }
    
    $price_info = get_item_price_from_db($db_conn, $item_code, $price_list_code);
    
    if ($price_info) {
        echo json_encode([
            'success' => true,
            'price' => $price_info['price'],
            'currency' => $price_info['currency'] ?: 'KES',
            'price_list_code' => $price_list_code
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'price' => 0,
            'currency' => 'KES',
            'price_list_code' => $price_list_code
        ]);
    }
    
    $db_conn->close();
    exit;
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
    price DECIMAL(15,4) DEFAULT 0,
    line_total DECIMAL(15,4) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'KES',
    posting_date DATE,
    delivery_date DATE,
    document_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$db_conn->query($table_sql);

// Flash message from session
$message = null;
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// ----------------- Handle form submission -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
   error_log('POST data: ' . json_encode($_POST));
   $card_code = $db_conn->real_escape_string($_POST['card_code'] ?? '');

    $item_codes = $_POST['item_code'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    
    // Get date inputs
    $posting_date = $_POST['posting_date'] ?? date('Y-m-d');
    $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d', strtotime('+1 day'));
    $document_date = $_POST['document_date'] ?? date('Y-m-d');

    if (!is_array($item_codes)) { $item_codes = [$item_codes]; }
    if (!is_array($quantities)) { $quantities = [$quantities]; }
    if (!is_array($prices)) { $prices = [$prices]; }

    $successful_inserts = 0;
    $errors = [];

    if ($card_code && count($item_codes) > 0) {
        // Determine customer name and price list
        $cust_name = '';
        $customer_price_list = get_customer_price_list($db_conn, $card_code);
        
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
        $total_amount = 0;
        
        // Debug logging for troubleshooting
        error_log("DEBUG FIXED: Processing " . count($item_codes) . " item rows");
        error_log("DEBUG FIXED: Item codes array: " . print_r($item_codes, true));
        error_log("DEBUG FIXED: Quantities array: " . print_r($quantities, true));
        
        foreach ($item_codes as $index => $code) {
            $code_safe = $db_conn->real_escape_string(trim($code ?? ''));
            $qty_val = intval($quantities[$index] ?? 0);
            $price_val = floatval($prices[$index] ?? 0);
            
            error_log("DEBUG FIXED: Item $index - Code: '$code_safe', Qty: $qty_val, Price: $price_val");
            
            if ($code_safe !== '' && $qty_val > 0) {
                // If no price provided, fetch from database
                if ($price_val <= 0) {
                    $price_info = get_item_price_from_db($db_conn, $code_safe, $customer_price_list);
                    $price_val = $price_info ? $price_info['price'] : 0;
                }
                
                $line_total = $price_val * $qty_val;
                $total_amount += $line_total;
                
                $insert_sql = "INSERT INTO Sales_order (sales_order_id, cust, card_code, item_code, quantity, price, line_total, currency, posting_date, delivery_date, document_date) VALUES ($sales_order_id, '" . $db_conn->real_escape_string($cust_name) . "', '$card_code', '$code_safe', $qty_val, $price_val, $line_total, 'KES', '$posting_date', '$delivery_date', '$document_date')";
                
                if ($db_conn->query($insert_sql) === TRUE) {
                    $successful_inserts++;
                    error_log("DEBUG: Sales order line added for item $code_safe qty $qty_val price $price_val (total: $line_total)");
                    
                    // Prepare SAP line with price
                    $sap_line = [
                        'ItemCode' => $code_safe,
                        'Quantity' => $qty_val,
                        'WarehouseCode' => $SAP_DEFAULT_WAREHOUSE
                    ];
                    
                    // Add price if available
                    if ($price_val > 0) {
                        $sap_line['Price'] = $price_val;
                    }
                    
                    $sap_lines[] = $sap_line;
                } else {
                    $errors[] = "Error inserting item $code_safe: " . $db_conn->error;
                }
            }
        }

        if ($successful_inserts > 0 && empty($errors)) {
            $message = "Sales order #$sales_order_id created ($successful_inserts item(s), Total: " . number_format($total_amount, 2) . " KES).";
            
            // Attempt to post to SAP with proper dates
            $sap_result_msg = '';
            if (!empty($sap_lines)) {
                $sap_err = '';
                $sap_docnum = null;
                
                if (sap_post_sales_order($SAP_SERVICE_LAYER_URL, $SAP_COMPANY_DB, $SAP_USERNAME, $SAP_PASSWORD, $card_code, $sap_lines, $SAP_DEFAULT_SALES_EMPLOYEE_CODE, $SAP_DEFAULT_BPL_ID, $sap_docnum, $sap_err, $sales_order_id, $document_date, $delivery_date, $posting_date)) {
                    $sap_result_msg = " Posted to SAP (DocNum: " . htmlspecialchars((string)$sap_docnum) . ").";
                } else {
                    $sap_result_msg = " Failed to post to SAP: " . htmlspecialchars($sap_err) . ".";
                }
                $message .= $sap_result_msg;
            }
        } elseif ($successful_inserts > 0 && !empty($errors)) {
            $message = "Sales order #$sales_order_id partially created ($successful_inserts item(s)). " . implode(' ', $errors);
        } else {
            // More detailed error message for debugging
            $debug_info = "DEBUG FIXED: No valid items found. ";
            $debug_info .= "Total item rows: " . count($item_codes) . ". ";
            
            $empty_items = 0;
            $zero_qty_items = 0;
            foreach ($item_codes as $index => $code) {
                $code_safe = trim($code ?? '');
                $qty_val = intval($quantities[$index] ?? 0);
                
                if ($code_safe === '') {
                    $empty_items++;
                } elseif ($qty_val <= 0) {
                    $zero_qty_items++;
                }
            }
            
            $debug_info .= "Empty items: $empty_items. Zero/negative qty items: $zero_qty_items.";
            error_log($debug_info);
            
            $message = "Please add at least one valid item with quantity > 0. (Empty: $empty_items, Zero qty: $zero_qty_items)";
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

$price_lists = [];
$result = $db_conn->query("SELECT price_list_code, price_list_name FROM price_lists ORDER BY price_list_code");
if ($result) {
    while ($row = $result->fetch_assoc()) { $price_lists[] = ['code'=>$row['price_list_code'], 'name'=>$row['price_list_name']]; }
    $result->free();
}

$db_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Sales Order (SAP) - Enhanced</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Enhanced styling with price functionality */
        :root {
            --sap-blue: #2c5aa0;
            --sap-light-blue: #e8f4fd;
            --sap-border-blue: #b8d4f0;
            --sap-gray: #f8f9fa;
            --sap-success: #28a745;
            --sap-warning: #ffc107;
            --sap-danger: #dc3545;
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
            grid-template-columns: 1fr 2fr 1fr 1fr;
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
        
        .customer-search-field .form-control, .customer-search-field .form-select {
            font-size: 14px;
            border-radius: 6px;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .customer-search-field .form-control:focus, .customer-search-field .form-select:focus {
            border-color: var(--sap-blue);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
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
        
        .price-loading {
            background-color: #fff3cd;
            border-color: #ffeaa7;
        }
        
        .price-found {
            background-color: #d1edff;
            border-color: var(--sap-blue);
        }
        
        .price-not-found {
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
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
    </style>
</head>
<body>
    <div class="sap-container">
        <div class="sap-header">
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Sales Order - Enhanced</span>
            <small class="ms-auto opacity-75">With Real-time Pricing</small>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert" style="margin: 15px 20px;">
                <i class="bi bi-info-circle-fill me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="post" id="sales-order-form">
            <!-- Customer Selection Section - Enhanced with Price List -->
            <div class="customer-section">
                <h5 class="mb-4">
                    <i class="bi bi-person-circle me-2"></i>
                    Customer Selection & Pricing
                </h5>
                
                <!-- Customer Search Grid -->
                <div class="customer-search-grid">
                    <div class="customer-search-field">
                        <label for="customer-card-code">
                            <i class="bi bi-hash me-1"></i>Card Code:
                        </label>
                        <input type="text" id="customer-card-code" name="card_code" class="form-control" 
                               placeholder="Enter card code" autocomplete="off">
                        <input type="hidden" id="selected-card-code" name="card_code_hidden">
                    </div>
                    
                    <div class="customer-search-field">
                        <label for="customer-name-search">
                            <i class="bi bi-person me-1"></i>Customer Name:
                        </label>
                        <input type="text" id="customer-name-search" class="form-control" 
                               placeholder="Enter customer name" autocomplete="off">
                        <div id="customer-suggestions" class="dropdown-menu w-100" style="display: none; max-height: 200px; overflow-y: auto;">
                        </div>
                    </div>
                    
                    <div class="customer-search-field">
                        <label for="price-list-select">
                            <i class="bi bi-tag me-1"></i>Price List:
                        </label>
                        <select id="price-list-select" name="price_list_code" class="form-select">
                            <option value="">Auto (Customer Default)</option>
                            <?php foreach ($price_lists as $pl): ?>
                                <option value="<?php echo $pl['code']; ?>">
                                    PL<?php echo $pl['code']; ?>: <?php echo htmlspecialchars($pl['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="customer-search-field">
                        <label>&nbsp;</label>
                        <button type="button" id="search-customer" class="btn btn-sap-primary w-100">
                            <i class="bi bi-search me-2"></i>Search Customer
                        </button>
                    </div>
                </div>
                
                <!-- Customer Info Display -->
                <div class="row mt-4" id="customer-info-section" style="display: none;">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Customer Details</h6>
                                <p><strong>Name:</strong> <span id="customer-name-display"></span></p>
                                <p><strong>Card Code:</strong> <span id="customer-code-display"></span></p>
                                <p><strong>Price List:</strong> <span id="customer-pricelist-display"></span></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Order Dates</h6>
                                <div class="mb-2">
                                    <label for="posting-date" class="form-label">Posting Date:</label>
                                    <input type="date" id="posting-date" name="posting_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="mb-2">
                                    <label for="delivery-date" class="form-label">Delivery Date:</label>
                                    <input type="date" id="delivery-date" name="delivery_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                </div>
                                <div>
                                    <label for="document-date" class="form-label">Document Date:</label>
                                    <input type="date" id="document-date" name="document_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Entry Section -->
            <div id="items-section" class="p-4">
                <h5 class="mb-3">
                    <i class="bi bi-table me-2"></i>
                    Order Items
                </h5>
                
                <div class="table-responsive">
                    <table class="table table-bordered items-entry-table">
                        <thead class="table-primary">
                            <tr>
                                <th width="25%">Item Code</th>
                                <th width="35%">Item Name</th>
                                <th width="10%">Quantity</th>
                                <th width="15%">Price (KES)</th>
                                <th width="15%">Line Total</th>
                            </tr>
                        </thead>
                        <tbody id="items-tbody">
                            <!-- Dynamic rows will be added here -->
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2">Total:</th>
                                <th><input type="text" id="total-qty" class="form-control" readonly></th>
                                <th></th>
                                <th><input type="text" id="total-amount" class="form-control" readonly></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="mt-3 text-center">
                    <button type="button" id="add-row" class="btn btn-sap-secondary me-2">
                        <i class="bi bi-plus-circle me-1"></i>Add Row
                    </button>
                    <button type="submit" id="submit-order" class="btn btn-sap-primary" style="display: none;">
                        <i class="bi bi-check-circle me-1"></i>Create Sales Order
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Global variables
        const customers = <?php echo json_encode($customers); ?>;
        const items = <?php echo json_encode($items); ?>;
        const priceLists = <?php echo json_encode($price_lists); ?>;
        let currentCustomer = null;
        let currentPriceList = null;
        
        // DOM elements
        const customerCardInput = document.getElementById('customer-card-code');
        const customerNameInput = document.getElementById('customer-name-search');
        const priceListSelect = document.getElementById('price-list-select');
        const searchBtn = document.getElementById('search-customer');
        const customerSuggestions = document.getElementById('customer-suggestions');
        const customerInfoSection = document.getElementById('customer-info-section');
        const itemsTbody = document.getElementById('items-tbody');
        const addRowBtn = document.getElementById('add-row');
        const submitBtn = document.getElementById('submit-order');
        
        // Utility functions
        function findCustomerByCode(code) {
            return customers.find(c => c.card_code === code);
        }
        
        function findCustomersByName(name) {
            return customers.filter(c => 
                c.card_name.toLowerCase().includes(name.toLowerCase())
            ).slice(0, 5);
        }
        
        function findItemByCode(code) {
            return items.find(i => i.item_code === code);
        }
        
        function findItemByName(name) {
            return items.find(i => i.item_name === name);
        }
        
        // Customer selection functions
        function showCustomerSuggestions(matches) {
            if (matches.length === 0) {
                customerSuggestions.style.display = 'none';
                return;
            }
            
            let html = '';
            matches.forEach(customer => {
                html += `
                    <div class="dropdown-item customer-suggestion" data-code="${customer.card_code}">
                        <strong>${customer.card_name}</strong><br>
                        <small class="text-muted">${customer.card_code}</small>
                    </div>
                `;
            });
            
            customerSuggestions.innerHTML = html;
            customerSuggestions.style.display = 'block';
            
            // Add click handlers
            customerSuggestions.querySelectorAll('.customer-suggestion').forEach(item => {
                item.addEventListener('click', () => {
                    const code = item.dataset.code;
                    const customer = findCustomerByCode(code);
                    selectCustomer(customer);
                });
            });
        }
        
        function selectCustomer(customer) {
            if (!customer) return;
            
            currentCustomer = customer;
            customerCardInput.value = customer.card_code;
            customerNameInput.value = customer.card_name;
            customerSuggestions.style.display = 'none';
            
            // Show customer info
            document.getElementById('customer-name-display').textContent = customer.card_name;
            document.getElementById('customer-code-display').textContent = customer.card_code;
            document.getElementById('customer-pricelist-display').textContent = priceListSelect.value || 'Auto (Customer Default)';
            customerInfoSection.style.display = 'block';
            
            // Enable form submission
            submitBtn.style.display = 'inline-block';
            
            // Update all existing item prices
            updateAllItemPrices();
            
            showToast('Customer selected successfully!', 'success');
        }
        
        // Price fetching functions
        async function fetchItemPrice(itemCode, cardCode = '', priceListCode = '') {
            try {
                const formData = new FormData();
                formData.append('action', 'get_item_price');
                formData.append('item_code', itemCode);
                formData.append('card_code', cardCode);
                formData.append('price_list_code', priceListCode);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                return data;
            } catch (error) {
                console.error('Error fetching price:', error);
                return { success: false, price: 0, currency: 'KES' };
            }
        }
        
        async function updateItemPrice(row) {
            const itemCodeInput = row.querySelector('.item-code-input');
            const priceInput = row.querySelector('.price-input');
            const lineTotalInput = row.querySelector('.line-total-input');
            const quantityInput = row.querySelector('.quantity-input');
            
            const itemCode = itemCodeInput.value.trim();
            if (!itemCode) {
                priceInput.value = '';
                priceInput.className = 'form-control price-input';
                updateLineTotal(row);
                return;
            }
            
            // Show loading state
            priceInput.className = 'form-control price-input price-loading';
            priceInput.value = 'Loading...';
            
            const cardCode = currentCustomer ? currentCustomer.card_code : '';
            const priceListCode = priceListSelect.value;
            
            const priceData = await fetchItemPrice(itemCode, cardCode, priceListCode);
            
            if (priceData.success && priceData.price > 0) {
                priceInput.value = parseFloat(priceData.price).toFixed(2);
                priceInput.className = 'form-control price-input price-found';
            } else {
                priceInput.value = '0.00';
                priceInput.className = 'form-control price-input price-not-found';
            }
            
            updateLineTotal(row);
            updateTotals();
        }
        
        function updateLineTotal(row) {
            const quantityInput = row.querySelector('.quantity-input');
            const priceInput = row.querySelector('.price-input');
            const lineTotalInput = row.querySelector('.line-total-input');
            
            const quantity = parseFloat(quantityInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            const lineTotal = quantity * price;
            
            lineTotalInput.value = lineTotal.toFixed(2);
        }
        
        function updateTotals() {
            let totalQty = 0;
            let totalAmount = 0;
            
            itemsTbody.querySelectorAll('tr').forEach(row => {
                const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
                const lineTotal = parseFloat(row.querySelector('.line-total-input').value) || 0;
                
                totalQty += quantity;
                totalAmount += lineTotal;
            });
            
            document.getElementById('total-qty').value = totalQty;
            document.getElementById('total-amount').value = totalAmount.toFixed(2) + ' KES';
        }
        
        function updateAllItemPrices() {
            itemsTbody.querySelectorAll('tr').forEach(row => {
                const itemCode = row.querySelector('.item-code-input').value.trim();
                if (itemCode) {
                    updateItemPrice(row);
                }
            });
        }
        
        // Item row management
        function addItemRow() {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="text" name="item_code[]" class="form-control item-code-input" 
                           placeholder="Enter item code" list="item-code-list">
                </td>
                <td>
                    <input type="text" name="item_name[]" class="form-control item-name-input" 
                           placeholder="Enter item name" list="item-name-list">
                </td>
                <td>
                    <input type="number" name="quantity[]" class="form-control quantity-input" 
                           min="1" placeholder="Qty">
                </td>
                <td>
                    <input type="number" name="price[]" class="form-control price-input" 
                           step="0.01" placeholder="Price" readonly>
                </td>
                <td>
                    <input type="text" class="form-control line-total-input" 
                           placeholder="0.00" readonly>
                </td>
            `;
            
            itemsTbody.appendChild(row);
            attachRowEvents(row);
            return row;
        }
        
        function attachRowEvents(row) {
            const itemCodeInput = row.querySelector('.item-code-input');
            const itemNameInput = row.querySelector('.item-name-input');
            const quantityInput = row.querySelector('.quantity-input');
            
            // Item code change
            itemCodeInput.addEventListener('input', async () => {
                const code = itemCodeInput.value.trim();
                if (code) {
                    const item = findItemByCode(code);
                    if (item) {
                        itemNameInput.value = item.item_name;
                    }
                    await updateItemPrice(row);
                }
            });
            
            // Item name change
            itemNameInput.addEventListener('input', async () => {
                const name = itemNameInput.value.trim();
                if (name) {
                    const item = findItemByName(name);
                    if (item) {
                        itemCodeInput.value = item.item_code;
                        await updateItemPrice(row);
                    }
                }
            });
            
            // Quantity change
            quantityInput.addEventListener('input', () => {
                updateLineTotal(row);
                updateTotals();
            });
        }
        
        // Event handlers
        customerNameInput.addEventListener('input', () => {
            const query = customerNameInput.value.trim();
            if (query.length >= 2) {
                const matches = findCustomersByName(query);
                showCustomerSuggestions(matches);
            } else {
                customerSuggestions.style.display = 'none';
            }
        });
        
        customerCardInput.addEventListener('input', () => {
            const code = customerCardInput.value.trim();
            if (code) {
                const customer = findCustomerByCode(code);
                if (customer) {
                    selectCustomer(customer);
                }
            }
        });
        
        searchBtn.addEventListener('click', () => {
            const code = customerCardInput.value.trim();
            const name = customerNameInput.value.trim();
            
            if (code) {
                const customer = findCustomerByCode(code);
                if (customer) {
                    selectCustomer(customer);
                } else {
                    showToast('Customer not found with this card code', 'warning');
                }
            } else if (name) {
                const matches = findCustomersByName(name);
                if (matches.length === 1) {
                    selectCustomer(matches[0]);
                } else if (matches.length > 1) {
                    showCustomerSuggestions(matches);
                    showToast('Multiple customers found. Please select one.', 'info');
                } else {
                    showToast('No customers found with this name', 'warning');
                }
            }
        });
        
        priceListSelect.addEventListener('change', () => {
            if (currentCustomer) {
                document.getElementById('customer-pricelist-display').textContent = 
                    priceListSelect.value || 'Auto (Customer Default)';
                updateAllItemPrices();
            }
        });
        
        addRowBtn.addEventListener('click', addItemRow);
        
        // Hide suggestions when clicking outside
        document.addEventListener('click', (e) => {
            if (!customerSuggestions.contains(e.target) && !customerNameInput.contains(e.target)) {
                customerSuggestions.style.display = 'none';
            }
        });
        
        // Form validation
        document.getElementById('sales-order-form').addEventListener('submit', (e) => {
            if (!currentCustomer) {
                e.preventDefault();
                showToast('Please select a customer first', 'warning');
                return;
            }
            
            const hasValidItems = Array.from(itemsTbody.querySelectorAll('tr')).some(row => {
                const itemCode = row.querySelector('.item-code-input').value.trim();
                const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
                return itemCode && quantity > 0;
            });
            
            if (!hasValidItems) {
                e.preventDefault();
                showToast('Please add at least one item with quantity > 0', 'warning');
                return;
            }
            
            showToast('Creating sales order...', 'info');
        });
        
        // Toast notification function
        function showToast(message, type = 'info') {
            const toastHtml = `
                <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi bi-info-circle me-2"></i>${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                container.style.zIndex = '9999';
                document.body.appendChild(container);
            }
            
            container.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = container.lastElementChild;
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', () => {
                toastElement.remove();
            });
        }
        
        // Initialize with 5 empty rows
        for (let i = 0; i < 5; i++) {
            addItemRow();
        }
    </script>
    
    <!-- Datalists for autocomplete -->
    <datalist id="item-code-list">
        <?php foreach ($items as $item): ?>
            <option value="<?php echo htmlspecialchars($item['item_code']); ?>">
                <?php echo htmlspecialchars($item['item_name']); ?>
            </option>
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="item-name-list">
        <?php foreach ($items as $item): ?>
            <option value="<?php echo htmlspecialchars($item['item_name']); ?>">
                <?php echo htmlspecialchars($item['item_code']); ?>
            </option>
        <?php endforeach; ?>
    </datalist>
</body>
</html>