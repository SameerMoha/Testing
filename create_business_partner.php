<?php
set_time_limit(600);

$SL_URL     = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME   = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD   = 'A2r@h@R001';
$COMPANYDB  = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';

// Config: UDF alias for PIN Number on BP (change if your alias differs)
$BP_UDF_PIN_ALIAS = 'U_PIN';

// Local DB
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function sl_login($slUrl, $username, $password, $companyDB, $cookieFile) {
    $loginUrl = rtrim($slUrl, '/') . '/Login';
    $payload = json_encode([
        'UserName'  => $username,
        'Password'  => $password,
        'CompanyDB' => $companyDB
    ]);

    error_log("DEBUG: SL Login attempt to $loginUrl with CompanyDB: $companyDB", 3, __DIR__ . '/debug.log');

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

    error_log("DEBUG: SL Login response HTTP: $http, Response: " . substr($resp, 0, 500), 3, __DIR__ . '/debug.log');

    if ($resp === false || $http < 200 || $http >= 300) {
        error_log("DEBUG: SL Login failed", 3, __DIR__ . '/debug.log');
        return false;
    }
    $json = json_decode($resp, true);
    error_log("DEBUG: SL Login success, SessionId: " . ($json['SessionId'] ?? 'N/A'), 3, __DIR__ . '/debug.log');
    return $json ?: false;
}

function sl_create_bp($slUrl, $cookieFile, $bpPayload) {
    $url = rtrim($slUrl, '/') . '/BusinessPartners?$format=json';

    error_log("DEBUG: Creating BP at $url, Payload: " . json_encode($bpPayload), 3, __DIR__ . '/debug.log');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($bpPayload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("DEBUG: BP Create response HTTP: $http, Response: " . substr($resp, 0, 1000), 3, __DIR__ . '/debug.log');

    if ($resp === false || $http < 200 || $http >= 300) {
        error_log("DEBUG: BP Create failed", 3, __DIR__ . '/debug.log');
        return [false, $resp, $http];
    }
    error_log("DEBUG: BP Create success", 3, __DIR__ . '/debug.log');
    return [true, json_decode($resp, true), $http];
}

// Get available numbering series for a document
function sl_get_available_series($slUrl, $cookieFile, $document) {
    $url = rtrim($slUrl, '/') . '/SeriesService_GetAvailableSeries';
    $payload = json_encode(['Document' => $document]);

    error_log("DEBUG: Fetching available series for $document", 3, __DIR__ . '/debug.log');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 20
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("DEBUG: Series response HTTP: $http, Response: " . substr($resp, 0, 1000), 3, __DIR__ . '/debug.log');

    if ($resp === false || $http < 200 || $http >= 300) {
        return [];
    }
    $json = json_decode($resp, true);
    // Expected format: { "value": [ { "Series": 1, "SeriesName": "C", "Indicator": "C", ... }, ... ] }
    if (isset($json['value']) && is_array($json['value'])) return $json['value'];
    return [];
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cardType  = $_POST['CardType'] ?? 'C';
    $cardCode  = trim($_POST['CardCode'] ?? '');
    $cardName  = trim($_POST['CardName'] ?? '');
    $phone     = trim($_POST['Phone1'] ?? '');
    $email     = trim($_POST['EmailAddress'] ?? '');
    $city      = trim($_POST['City'] ?? '');
    $county    = trim($_POST['County'] ?? '');
    $street    = trim($_POST['Street'] ?? '');
    $zip       = trim($_POST['ZipCode'] ?? '');
    $country   = trim($_POST['Country'] ?? '');
    $contactNm = trim($_POST['ContactName'] ?? '');
    $contactPh = trim($_POST['ContactPhone'] ?? '');
    $contactEm = trim($_POST['ContactEmail'] ?? '');
    $seriesInp = trim($_POST['Series'] ?? '');
    $pinNumber = trim($_POST['PinNumber'] ?? '');
    $federalTaxId = trim($_POST['FederalTaxID'] ?? '');

    if ($cardName === '') {
        header('Location: business_partners.php?status=error&message=' . urlencode('CardName is required'));
        exit;
    }
    // Let SAP B1 auto-generate CardCode if not provided

    // Login to SL
    $login = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
    if ($login === false) {
        header('Location: business_partners.php?status=error&message=' . urlencode('Service Layer login failed'));
        exit;
    }

    // Normalize Country to 2-letter ISO code (A-Z). If invalid, omit from payload.
    $countryIso = strtoupper(preg_replace('/[^A-Za-z]/', '', $country));
    if (strlen($countryIso) !== 2) {
        $countryIso = '';
    }

    // If CardCode is empty, try to select a Series matching CardType indicator (C/S/L)
    $seriesId = null;
    if ($cardCode === '') {
        // Prefer user-provided Series if given
        if ($seriesInp !== '' && ctype_digit($seriesInp)) {
            $seriesId = (int)$seriesInp;
        } else if ($cardType === 'C') {
            // Default Customer series ID per user input
            $seriesId = 68;
        } else {
            // Attempt auto-fetch; may fail if endpoint not available
            $allSeries = sl_get_available_series($SL_URL, $COOKIEFILE, 'oBusinessPartners');
            if (!empty($allSeries)) {
                $indicator = strtoupper(substr($cardType, 0, 1));
                foreach ($allSeries as $s) {
                    $ind = strtoupper($s['Indicator'] ?? ($s['SeriesName'] ?? ''));
                    if ($ind === $indicator) {
                        $seriesId = $s['Series'] ?? null;
                        break;
                    }
                }
                if ($seriesId === null && isset($allSeries[0]['Series'])) {
                    $seriesId = $allSeries[0]['Series'];
                }
            }
        }
    }

    // Build minimal BP payload - let SAP auto-generate CardCode
    $payload = [
        'CardType' => $cardType,
        'CardName' => $cardName
    ];
    
    // Only include CardCode if explicitly provided
    if ($cardCode !== '') {
        $payload['CardCode'] = $cardCode;
    }
    // If no CardCode, include Series if found
    if ($cardCode === '' && $seriesId !== null) {
        $payload['Series'] = (int)$seriesId;
    }
    
    // Add optional fields only if they have values
    if ($phone) $payload['Phone1'] = $phone;
    if ($email) $payload['EmailAddress'] = $email;
    if ($city) $payload['City'] = $city;
    if ($county) $payload['County'] = $county;
    
    // Add address only if we have address data
    $hasAddress = $street || $zip || $city || $countryIso;
    if ($hasAddress) {
        $address = [
            'AddressName' => 'Primary',
            'AddressType' => 'bo_ShipTo'
        ];
        if ($street) $address['Street'] = $street;
        if ($zip) $address['ZipCode'] = $zip;
        if ($city) $address['City'] = $city;
        if ($countryIso) $address['Country'] = $countryIso;
        
        $payload['BPAddresses'] = [$address];
    }
    
    // Add contact only if we have contact data
    $hasContact = $contactNm || $contactPh || $contactEm;
    if ($hasContact) {
        $contact = [];
        if ($contactNm) $contact['Name'] = $contactNm;
        if ($contactPh) $contact['Telephone1'] = $contactPh;
        if ($contactEm) $contact['E_Mail'] = $contactEm;
        
        $payload['ContactEmployees'] = [$contact];
    }
    // Add PIN UDF if provided
    if ($pinNumber !== '' && $BP_UDF_PIN_ALIAS) {
        $payload[$BP_UDF_PIN_ALIAS] = $pinNumber;
    }
    // Add standard FederalTaxID if provided
    if ($federalTaxId !== '') {
        $payload['FederalTaxID'] = $federalTaxId;
    }
    // CardCode already set above and required

    list($ok, $resp, $http) = sl_create_bp($SL_URL, $COOKIEFILE, $payload);
    if (!$ok) {
        $err = is_string($resp) ? $resp : json_encode($resp);
        header('Location: business_partners.php?status=error&message=' . urlencode('Create failed (HTTP ' . $http . '): ' . $err));
        exit;
    }

    // Figure out created values
    $createdCode = $resp['CardCode'] ?? ($cardCode ?: 'AUTO-GENERATED');
    $createdName = $resp['CardName'] ?? $cardName;

    // Insert into local DB
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($conn->connect_error) {
        header('Location: business_partners.php?status=error&message=' . urlencode('Local DB error: ' . $conn->connect_error));
        exit;
    }
    $conn->query("CREATE DATABASE IF NOT EXISTS `$DB_NAME`");
    $conn->select_db($DB_NAME);
    $conn->query("CREATE TABLE IF NOT EXISTS business_partners (
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
    )");

    $address = trim(implode(', ', array_filter([$street, $city . ($zip ? ' ' . $zip : ''), $countryIso ?: $country])));
    $contact = trim(implode(' ', array_filter([$contactNm, $contactPh, $contactEm])));

    $stmt = $conn->prepare("INSERT INTO business_partners (card_code, card_name, phone, city, county, email, credit_limit, current_balance, address, contact) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ");
    $zero = 0.0; // default values
    $stmt->bind_param("ssssssddss", $createdCode, $createdName, $phone, $city, $county, $email, $zero, $zero, $address, $contact);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    header('Location: business_partners.php?status=success&message=' . urlencode('Created BP ' . $createdCode . ' - ' . $createdName));
    exit;
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Create Business Partner</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 18px; }
        form { max-width: 800px; }
        .row { display:flex; gap:12px; }
        .field { flex:1; margin-bottom:10px; }
        label { display:block; font-weight:bold; margin-bottom:4px; }
        input, select { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; }
        .actions { margin-top: 12px; }
        .btn { display:inline-block; background:#28a745; color:#fff; padding:8px 12px; text-decoration:none; border-radius:4px; border:0; cursor:pointer; }
        a.btn-link { background:#6c757d; }
    </style>
</head>
<body>
    <h2>Create Business Partner</h2>
    <form method="post" action="">
        <div class="row">
            <div class="field">
                <label for="CardType">Type</label>
                <select id="CardType" name="CardType">
                    <option value="C">Customer</option>
                    <option value="S">Supplier</option>
                    <option value="L">Lead</option>
                </select>
            </div>
            <div class="field">
                <label for="CardCode">CardCode (optional)</label>
                <input type="text" id="CardCode" name="CardCode" placeholder="Leave empty for auto-generation">
            </div>
            <div class="field">
                <label for="Series">Series (optional)</label>
                <input type="text" id="Series" name="Series" placeholder="NNM1.Series (numeric)">
            </div>
            <div class="field">
                <label for="CardName">CardName *</label>
                <input type="text" id="CardName" name="CardName" required>
            </div>
        </div>

        <div class="row">
            <div class="field">
                <label for="Phone1">Phone</label>
                <input type="text" id="Phone1" name="Phone1">
            </div>
            <div class="field">
                <label for="EmailAddress">Email</label>
                <input type="email" id="EmailAddress" name="EmailAddress">
            </div>
            <div class="field">
                <label for="PinNumber">PIN Number</label>
                <input type="text" id="PinNumber" name="PinNumber" placeholder="Tax PIN / UDF">
            </div>
            <div class="field">
                <label for="FederalTaxID">Federal Tax ID</label>
                <input type="text" id="FederalTaxID" name="FederalTaxID" placeholder="Standard field">
            </div>
        </div>

        <div class="row">
            <div class="field">
                <label for="Street">Street</label>
                <input type="text" id="Street" name="Street">
            </div>
            <div class="field">
                <label for="City">City</label>
                <input type="text" id="City" name="City">
            </div>
            <div class="field">
                <label for="County">County</label>
                <input type="text" id="County" name="County">
            </div>
            <div class="field">
                <label for="ZipCode">Zip</label>
                <input type="text" id="ZipCode" name="ZipCode">
            </div>
            <div class="field">
                <label for="Country">Country</label>
                <input type="text" id="Country" name="Country" placeholder="e.g., GB, US">
            </div>
        </div>

        <fieldset>
            <legend>Primary Contact (optional)</legend>
            <div class="row">
                <div class="field">
                    <label for="ContactName">Name</label>
                    <input type="text" id="ContactName" name="ContactName">
                </div>
                <div class="field">
                    <label for="ContactPhone">Phone</label>
                    <input type="text" id="ContactPhone" name="ContactPhone">
                </div>
                <div class="field">
                    <label for="ContactEmail">Email</label>
                    <input type="email" id="ContactEmail" name="ContactEmail">
                </div>
            </div>
        </fieldset>

        <div class="actions">
            <button class="btn" type="submit">Create</button>
            <a class="btn btn-link" href="business_partners.php">Cancel</a>
        </div>
    </form>
</body>
</html>


