<?php
$db = new mysqli('localhost', 'root', '', 'customer_test');

echo "=== SYNC_ITEMS.PHP RESULTS ===\n";

$items_result = $db->query('SELECT COUNT(*) as count FROM items');
$items_count = $items_result->fetch_assoc()['count'];
echo "Items in database: $items_count\n";

$pl_result = $db->query('SELECT COUNT(*) as count FROM price_lists');
$pl_count = $pl_result->fetch_assoc()['count'];
echo "Price lists in database: $pl_count\n";

echo "\n=== ALL PRICE LISTS ===\n";
$pl_all = $db->query('SELECT price_list_code, price_list_name FROM price_lists ORDER BY price_list_code');
while ($row = $pl_all->fetch_assoc()) {
    echo sprintf("PL%2d: %s\n", $row['price_list_code'], $row['price_list_name']);
}

echo "\n=== SAMPLE ITEMS ===\n";
$items_sample = $db->query('SELECT item_code, item_name FROM items ORDER BY item_code LIMIT 10');
while ($row = $items_sample->fetch_assoc()) {
    echo "{$row['item_code']}: {$row['item_name']}\n";
}

echo "\n=== COMPARISON WITH ITEM_PRICES TABLE ===\n";
$item_prices_count = $db->query('SELECT COUNT(DISTINCT item_code) as count FROM item_prices');
$ip_count = $item_prices_count->fetch_assoc()['count'];
echo "Unique items in item_prices table: $ip_count\n";
echo "Items in items table: $items_count\n";
echo "Note: sync_items.php gets ALL items, sync_price_lists.php gets items with pricing data only\n";
?>