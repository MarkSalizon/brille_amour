<?php

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/catalog.php';

ensureCatalogSchema($conn);

$result = $conn->query("
    SELECT
        p.id,
        p.name,
        p.unit,
        p.inventory_id,
        i.name AS inventory_name,
        i.quantity AS inventory_quantity,
        i.unit AS inventory_unit
    FROM products p
    LEFT JOIN inventory i ON i.id = p.inventory_id
    WHERE p.status = 'active'
    ORDER BY p.name
");

while ($row = $result->fetch_assoc()) {
    echo $row['name']
        . ' | inventory_id=' . (int)($row['inventory_id'] ?? 0)
        . ' | inventory=' . ($row['inventory_name'] ?: 'NONE')
        . ' | qty=' . ($row['inventory_quantity'] ?? 'NULL')
        . ' ' . ($row['inventory_unit'] ?: '')
        . PHP_EOL;
}
