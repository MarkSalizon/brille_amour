<?php
require_once '../includes/db.php';
require_once '../includes/catalog.php';
require_once '../includes/product_sizes.php';
require_once '../includes/product_images.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ensureCatalogSchema($conn);

$products = [];
$result = $conn->query("
    SELECT
        p.id,
        p.category_id,
        p.name,
        p.description,
        p.image,
        p.unit_price,
        p.order_mode,
        p.unit,
        p.inventory_id,
        c.name AS category_name,
        i.quantity AS inventory_quantity,
        i.reorder_level AS inventory_reorder_level,
        i.unit AS inventory_unit
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory i ON i.id = p.inventory_id
    WHERE p.status = 'active'
    ORDER BY
        FIELD(p.priority, 'urgent', 'high', 'normal', 'low'),
        CASE WHEN p.sort_order > 0 AND p.sort_order < 999 THEN 0 ELSE 1 END,
        p.sort_order,
        c.name,
        p.name
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $inventoryId = (int)($row['inventory_id'] ?? 0);
        $quantity = $row['inventory_quantity'] === null ? null : (float)$row['inventory_quantity'];
        $reorderLevel = $row['inventory_reorder_level'] === null ? null : (float)$row['inventory_reorder_level'];
        $isAvailable = $inventoryId === 0 || $quantity === null || $quantity >= 1;
        $isLowStock = $isAvailable && $quantity !== null && $reorderLevel !== null && $reorderLevel > 0 && $quantity <= $reorderLevel;

        $products[] = [
            'id' => (int)$row['id'],
            'category_id' => (int)($row['category_id'] ?? 0),
            'category_name' => (string)($row['category_name'] ?? 'Custom Product'),
            'name' => (string)$row['name'],
            'sample_image_index' => productSampleImageIndex((string)$row['name']),
            'image_url' => productDisplayImage($row),
            'description' => (string)($row['description'] ?? ''),
            'unit_price' => (float)$row['unit_price'],
            'order_mode' => (string)($row['order_mode'] ?? 'regular'),
            'size_options' => printshopSizeOptions((string)$row['name'], (float)$row['unit_price'], $conn, (int)$row['id']),
            'unit' => (string)($row['unit'] ?? 'piece'),
            'inventory_id' => $inventoryId,
            'inventory_quantity' => $quantity,
            'inventory_unit' => (string)($row['inventory_unit'] ?? $row['unit'] ?? 'piece'),
            'available' => $isAvailable,
            'low_stock' => $isLowStock,
        ];
    }
}

$availability = printshopProductAvailabilityMap($conn, $products);
foreach ($products as &$product) {
    $item = $availability[(int)$product['id']] ?? ['available' => false, 'reason' => 'Availability could not be confirmed.', 'max_order_qty' => 0];
    $product['available'] = $product['order_mode'] === 'preorder' ? true : (bool)$item['available'];
    $product['unavailable_reason'] = (string)$item['reason'];
    $product['max_order_qty'] = (int)$item['max_order_qty'];
}
unset($product);

$categories = array_map(static function (array $category): array {
    return [
        'id' => (int)$category['id'],
        'name' => (string)$category['name'],
    ];
}, getActiveCategoriesList($conn));

echo json_encode([
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'categories' => $categories,
    'products' => $products,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
