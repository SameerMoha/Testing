<?php
// price_list.php
// Functions to retrieve price list master data

// Database config
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

// Connect to DB
function get_db_connection() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli($GLOBALS['DB_HOST'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $GLOBALS['DB_NAME']);
        if ($conn->connect_error) {
            error_log("Price list DB connection failed: " . $conn->connect_error);
            return null;
        }
    }
    return $conn;
}

// Get all price lists
function get_price_lists() {
    $conn = get_db_connection();
    if (!$conn) return [];

    $result = $conn->query("SELECT * FROM price_lists ORDER BY price_list_code");
    $lists = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $lists[] = $row;
        }
        $result->free();
    }
    return $lists;
}

// Get special prices for an item
function get_item_prices($item_code, $price_list_code = null) {
    $conn = get_db_connection();
    if (!$conn) return [];

    $where = "item_code = '" . $conn->real_escape_string($item_code) . "'";
    if ($price_list_code !== null) {
        $where .= " AND price_list_code = " . intval($price_list_code);
    }

    $result = $conn->query("SELECT * FROM special_prices WHERE $where ORDER BY price_list_code");
    $prices = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $prices[] = $row;
        }
        $result->free();
    }
    return $prices;
}

// Get price for specific item and price list
function get_item_price($item_code, $price_list_code) {
    $prices = get_item_prices($item_code, $price_list_code);
    return !empty($prices) ? $prices[0]['price'] : null;
}

// Get default price list (first one)
function get_default_price_list() {
    $lists = get_price_lists();
    return !empty($lists) ? $lists[0] : null;
}

// Generate price list report (for retrieval)
function generate_price_list_report($price_list_code = null) {
    $conn = get_db_connection();
    if (!$conn) return [];

    $query = "SELECT sp.*, pl.price_list_name, i.item_name
              FROM special_prices sp
              JOIN price_lists pl ON sp.price_list_code = pl.price_list_code
              LEFT JOIN items i ON sp.item_code = i.item_code";
    if ($price_list_code !== null) {
        $query .= " WHERE sp.price_list_code = " . intval($price_list_code);
    }
    $query .= " ORDER BY sp.item_code, sp.price_list_code";

    $result = $conn->query($query);
    $report = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $report[] = $row;
        }
        $result->free();
    }
    return $report;
}

// If called directly, output JSON of price lists
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? 'lists';

    switch ($action) {
        case 'lists':
            echo json_encode(get_price_lists());
            break;
        case 'item_prices':
            $item_code = $_GET['item_code'] ?? '';
            $price_list = isset($_GET['price_list']) ? intval($_GET['price_list']) : null;
            echo json_encode(get_item_prices($item_code, $price_list));
            break;
        case 'report':
            $price_list = isset($_GET['price_list']) ? intval($_GET['price_list']) : null;
            echo json_encode(generate_price_list_report($price_list));
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
}
?>