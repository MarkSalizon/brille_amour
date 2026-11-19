<?php

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/catalog.php';

ensureCatalogSchema($conn);

$result = $conn->query("
    SELECT name, unit_price
    FROM products
    WHERE status = 'active'
    ORDER BY name
");

if (!$result) {
    fwrite(STDERR, "Failed to load products.\n");
    exit(1);
}

while ($row = $result->fetch_assoc()) {
    echo $row['name'] . ' | ' . number_format((float)$row['unit_price'], 2) . PHP_EOL;
}
