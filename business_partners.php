<?php
set_time_limit(300);

// Local MySQL database setup
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists and select it
$conn->query("CREATE DATABASE IF NOT EXISTS `$DB_NAME`");
$conn->select_db($DB_NAME);

// Ensure table exists (mirror of sync script)
$table_sql = "CREATE TABLE IF NOT EXISTS business_partners (
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
)";
$conn->query($table_sql);

// Fetch rows from local table
$rows = [];
$result = $conn->query("SELECT card_code, card_name, phone, city, county, email, credit_limit, current_balance, address, contact FROM business_partners ORDER BY card_name ASC");
if ($result) {
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $result->free();
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
$status = isset($_GET['status']) ? $_GET['status'] : '';
$message = isset($_GET['message']) ? $_GET['message'] : '';
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Business Partners (Local)</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 18px; }
        table { border-collapse: collapse; width: 100%; font-size:14px; }
        th, td { border: 1px solid #ddd; padding: 8px; vertical-align:top; }
        th { background: #f2f2f2; text-align: left; }
        tr:nth-child(even){background-color: #fafafa;}
        .actions { margin-bottom: 12px; }
        .btn { display:inline-block; background:#007bff; color:#fff; padding:8px 12px; text-decoration:none; border-radius:4px; }
        .btn:visited { color:#fff; }
        .muted { color:#666; font-size:13px; }
        .right { text-align:right; }
        .notice { padding:10px; margin:12px 0; border-left:4px solid #007bff; background:#f7fbff; }
        .notice.error { border-left-color:#dc3545; background:#fff7f7; }
    </style>
    </head>
<body>
    <h2>Business Partners (Local DB)</h2>
    <div class="actions">
        <a class="btn" href="sync_business_partners.php" title="Run sync to refresh local data">Sync from Service Layer</a>
        <a class="btn" href="create_business_partner.php" title="Create a new Business Partner" style="background:#28a745;">Add Business Partner</a>
        <span class="muted">This page displays data from the local `business_partners` table.</span>
    </div>

    <?php if ($status || $message): ?>
        <div class="notice <?= $status === 'error' ? 'error' : '' ?>">
            <?= h($message ?: ($status === 'success' ? 'Operation completed successfully.' : '')) ?>
        </div>
    <?php endif; ?>

    <?php if (count($rows) === 0): ?>
        <p>No data in local table. Click "Sync from Service Layer" to load.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>CardCode</th>
                    <th>CardName</th>
                    <th>Phone</th>
                    <th>City</th>
                    <th>County</th>
                    <th>Email</th>
                    <th>CreditLimit</th>
                    <th>CurrentBalance</th>
                    <th>Primary Address</th>
                    <th>Primary Contact</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td><?= ($i+1) ?></td>
                    <td><?= h($r['card_code']) ?></td>
                    <td><?= h($r['card_name']) ?></td>
                    <td><?= h($r['phone']) ?></td>
                    <td><?= h($r['city']) ?></td>
                    <td><?= h($r['county']) ?></td>
                    <td><?= h($r['email']) ?></td>
                    <td class="right"><?= $r['credit_limit'] !== null ? number_format((float)$r['credit_limit'], 2) : '' ?></td>
                    <td class="right"><?= $r['current_balance'] !== null ? number_format((float)$r['current_balance'], 2) : '' ?></td>
                    <td><?= nl2br(h($r['address'])) ?></td>
                    <td><?= nl2br(h($r['contact'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php $conn->close(); ?>
</body>
</html>


