<?php
$db = new mysqli('localhost', 'root', '', 'customer_test');

echo "=== PRICE LISTS ===\n";
$result = $db->query('SELECT * FROM price_lists ORDER BY price_list_code LIMIT 10');
while ($row = $result->fetch_assoc()) {
    echo "Price List {$row['price_list_code']}: {$row['price_list_name']}\n";
}

echo "\n=== SAMPLE ITEM PRICES (with zeros) ===\n";
$result = $db->query('SELECT item_code, item_name, price_list_code, price FROM item_prices ORDER BY item_code, price_list_code LIMIT 20');
while ($row = $result->fetch_assoc()) {
    echo "Item: {$row['item_code']} | Price List: {$row['price_list_code']} | Price: {$row['price']}\n";
}

echo "\n=== PRICE STATISTICS ===\n";
$result = $db->query('SELECT COUNT(*) as total_records, COUNT(CASE WHEN price = 0 THEN 1 END) as zero_prices, COUNT(CASE WHEN price > 0 THEN 1 END) as non_zero_prices FROM item_prices');
$stats = $result->fetch_assoc();
echo "Total price records: {$stats['total_records']}\n";
echo "Zero price records: {$stats['zero_prices']}\n";
echo "Non-zero price records: {$stats['non_zero_prices']}\n";

echo "\n=== ITEMS WITH PRICE LIST 1 SPECIFICALLY ===\n";
$result = $db->query('SELECT item_code, item_name, price FROM item_prices WHERE price_list_code = 1 ORDER BY item_code LIMIT 15');
while ($row = $result->fetch_assoc()) {
    echo "Item: {$row['item_code']} ({$row['item_name']}) | PL1 Price: {$row['price']}\n";
}
?>