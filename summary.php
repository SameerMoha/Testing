<?php
$db = new mysqli('localhost', 'root', '', 'customer_test');

echo "=== COMPLETE DATA SUMMARY ===\n";

// Get total items
$result = $db->query('SELECT COUNT(DISTINCT item_code) as total_items FROM item_prices');
$totalItems = $result->fetch_assoc()['total_items'];

// Get total price lists  
$result = $db->query('SELECT COUNT(*) as total_pl FROM price_lists');
$totalPL = $result->fetch_assoc()['total_pl'];

// Get total price records
$result = $db->query('SELECT COUNT(*) as total_records FROM item_prices');
$totalRecords = $result->fetch_assoc()['total_records'];

// Get zero vs non-zero prices
$result = $db->query('SELECT 
    COUNT(CASE WHEN price = 0 THEN 1 END) as zero_prices,
    COUNT(CASE WHEN price > 0 THEN 1 END) as non_zero_prices
    FROM item_prices');
$stats = $result->fetch_assoc();

echo "Total Items: $totalItems\n";
echo "Total Price Lists: $totalPL\n";
echo "Total Price Records: $totalRecords\n";
echo "Zero-value prices: {$stats['zero_prices']}\n";
echo "Non-zero prices: {$stats['non_zero_prices']}\n";

echo "\n=== VERIFICATION ===\n";
echo "Expected records (Items × Price Lists): " . ($totalItems * $totalPL) . "\n";
echo "Actual records: $totalRecords\n";
echo "Match: " . (($totalItems * $totalPL) == $totalRecords ? "YES ✓" : "NO ✗") . "\n";

echo "\n=== ALL ITEMS WITH THEIR CODES ===\n";
$result = $db->query('SELECT DISTINCT item_code, item_name FROM item_prices ORDER BY item_code');
$i = 1;
while ($row = $result->fetch_assoc()) {
    echo sprintf("%2d. %s (%s)\n", $i++, $row['item_code'], $row['item_name']);
}

echo "\n=== SAMPLE: ITEM 2239 ACROSS ALL PRICE LISTS ===\n";
$result = $db->query('SELECT ip.price_list_code, pl.price_list_name, ip.price 
                      FROM item_prices ip 
                      LEFT JOIN price_lists pl ON ip.price_list_code = pl.price_list_code 
                      WHERE ip.item_code = "2239" 
                      ORDER BY ip.price_list_code');
while ($row = $result->fetch_assoc()) {
    $plName = $row['price_list_name'] ?: 'Unknown';
    echo sprintf("PL%2d (%s): %s\n", $row['price_list_code'], $plName, number_format($row['price'], 2));
}
?>