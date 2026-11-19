<?php
/**
 * Size-based and bundle pricing for the print-shop catalog.
 * Fixed, explicitly listed options (tarpaulin, cards, flyers/brochures, stickers/labels,
 * ID-picture packages and photo printing) use the prices below. Other products use
 * Admin > Products base price multiplied by the corresponding size factor.
 */
/** Keep legacy order/cart names compatible after the combined products are renamed. */
function printshopCanonicalPriceName(string $name): string {
    $name = strtolower(trim($name));
    return match ($name) {
        'flyers & brochures' => 'flyers/brochure',
        'stickers & labels' => 'stickers/labels',
        default => $name,
    };
}

function printshopSizeRules(string $name): array {
    $name = printshopCanonicalPriceName($name);
    $rules = [
        'tarpaulin printing' => [['2 × 3 ft',1], ['2 × 4 ft',1], ['3 × 3 ft',1], ['3 × 4 ft',1], ['3 × 5 ft',1], ['4 × 4 ft',1], ['4 × 5 ft',1], ['4 × 6 ft',1], ['5 × 6 ft',1], ['5 × 7 ft',1], ['5 × 8 ft',1], ['6 × 7 ft',1], ['6 × 8 ft',1], ['6 × 9 ft',1], ['6 × 10 ft',1]],
        'sintra board' => [['A4 (8.3 × 11.7 in)',1], ['A3 (11.7 × 16.5 in)',2], ['2 × 3 ft',4.4], ['3 × 4 ft',8.8]],
        'posters' => [['A4',1], ['A3',2], ['A2',4], ['A1',8]],
        'customized shirts' => [['S',1], ['M',1], ['L',1], ['XL',1], ['2XL',1.15], ['3XL',1.3]],
        'acrylic keychains' => [['1.5 in',1], ['2 in',1.25], ['2.5 in',1.6]],
        'button pins' => [['32 mm',1], ['44 mm',1.25], ['58 mm',1.5]],
        'ref magnets' => [['2 × 2 in',1], ['3 × 3 in',1.65], ['4 × 4 in',2.5]],
        'bookmarks' => [['2 × 6 in',1], ['2 × 8 in',1.3]],
        'business cards' => [['100 pcs · Standard (3.5 × 2 in)',1]],
        'flyers/brochure' => [['A6 Flyers · 100 pcs',1], ['A5 Flyers · 500 pcs',1], ['A4 Flyers · 20 pcs',1], ['A4 Flyers · 100 pcs',1], ['Custom Flyer / Brochure · 1 pc',1]],
        'stickers/labels' => [['2 × 2 in · 80 pcs',1], ['3 × 3 in · 80 pcs',1], ['4 × 6 in · 20 pcs',1], ['2 × 2 in · 1 pc',1], ['3 × 3 in · 1 pc',1], ['4 × 6 in · 1 pc',1]],
        'invitations' => [['A6',1], ['A5',1.5], ['A4',2.5]],
        'photo printing' => [['4R (4 × 6 in)',1], ['Polaroid-style (3.5 × 4.2 in)',1], ['5R (5 × 7 in)',1], ['8R (8 × 10 in)',1], ['A4',1]],
        'id picture printing' => [['ID picture package · 4 pcs 2×2 + 8 pcs 1×1 + 2 passport',1]],
        'document printing' => [
            ['A4 · Black & White · per page',1], ['A4 · Colored · per page',1],
            ['Short · Black & White · per page',1], ['Short · Colored · per page',1],
            ['Long · Black & White · per page',1], ['Long · Colored · per page',1],
        ],
        'lamination' => [['ID / card',1], ['A5',1.5], ['A4',2], ['Legal',2.7], ['A3',4]],
    ];
    return $rules[$name] ?? [];
}

function printshopFixedOptionPrices(): array {
    // These are explicitly confirmed by the shop or, for unspecified options,
    // stated starter prices. One cart quantity = one selected set / bundle / piece.
    return [
        'tarpaulin printing' => [
            '2 × 3 ft'=>120.00, '2 × 4 ft'=>160.00, '3 × 3 ft'=>180.00,
            '3 × 4 ft'=>240.00, '3 × 5 ft'=>300.00, '4 × 4 ft'=>320.00,
            '4 × 5 ft'=>400.00, '4 × 6 ft'=>480.00, '5 × 6 ft'=>600.00,
            '5 × 7 ft'=>700.00, '5 × 8 ft'=>800.00, '6 × 7 ft'=>840.00,
            '6 × 8 ft'=>960.00, '6 × 9 ft'=>1080.00, '6 × 10 ft'=>1200.00,
        ],
        'business cards' => ['100 pcs · Standard (3.5 × 2 in)'=>290.00],
        'flyers/brochure' => [
            'A6 Flyers · 100 pcs'=>175.00, // chosen within ₱85–265 customer-provided range
            'A5 Flyers · 500 pcs'=>2100.00,
            'A4 Flyers · 20 pcs'=>100.00,
            'A4 Flyers · 100 pcs'=>443.00,
            'Custom Flyer / Brochure · 1 pc'=>20.00,
        ],
        'stickers/labels' => [
            '2 × 2 in · 80 pcs'=>107.00, '3 × 3 in · 80 pcs'=>207.00,
            '4 × 6 in · 20 pcs'=>109.00,
            '2 × 2 in · 1 pc'=>3.00, '3 × 3 in · 1 pc'=>4.00,
            '4 × 6 in · 1 pc'=>8.00, // provisional individually priced options
        ],
        // One cart quantity = one printed page.
        'document printing' => [
            'A4 · Black & White · per page'=>3.00,
            'A4 · Colored · per page'=>5.00,
            'Short · Black & White · per page'=>3.00,
            'Short · Colored · per page'=>5.00,
            'Long · Black & White · per page'=>3.00,
            'Long · Colored · per page'=>5.00,
        ],
        'id picture printing' => [
            'ID picture package · 4 pcs 2×2 + 8 pcs 1×1 + 2 passport'=>45.00,
        ],
    ];
}

/** Admin-edited price overrides, keyed by the stable product ID and a preset size/package label.
 *  Defaults remain in this file; absent override rows mean use the existing default price.
 */
function ensureProductSizePriceSchema(mysqli $conn): void {
    static $ready = [];
    $key = spl_object_id($conn);
    if (isset($ready[$key])) return;
    if (!$conn->query("CREATE TABLE IF NOT EXISTS product_size_prices (
        product_id INT NOT NULL, size_label VARCHAR(190) NOT NULL,
        price DECIMAL(10,2) NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (product_id, size_label), INDEX idx_product_size_prices_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) {
        throw new RuntimeException('Product size pricing table is unavailable.');
    }
    // Admin-managed size lists: once a product is "customized", ONLY its rows here are offered
    // (admins can add, rename, re-price, reorder and delete sizes). Others keep the defaults above.
    if (!$conn->query("CREATE TABLE IF NOT EXISTS product_size_list (
        product_id INT NOT NULL, size_label VARCHAR(190) NOT NULL,
        price DECIMAL(10,2) NOT NULL, sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (product_id, size_label), INDEX idx_product_size_list_product (product_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
        || !$conn->query("CREATE TABLE IF NOT EXISTS product_size_config (
        product_id INT NOT NULL PRIMARY KEY, customized TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Product size list tables are unavailable.');
    }
    $ready[$key] = true;
}

function printshopSizePriceOverrides(mysqli $conn, int $productId): array {
    if ($productId < 1) return [];
    ensureProductSizePriceSchema($conn);
    $stmt = $conn->prepare('SELECT size_label, price FROM product_size_prices WHERE product_id=?');
    if (!$stmt) throw new RuntimeException('Could not read product size prices.');
    $stmt->bind_param('i', $productId);
    if (!$stmt->execute()) throw new RuntimeException('Could not load product size prices.');
    $overrides = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $overrides[(string)$row['size_label']] = (float)$row['price'];
    }
    $stmt->close();
    return $overrides;
}

/** Admin-managed size list for a product, or null when the product still uses the built-in defaults. */
function printshopCustomSizeList(mysqli $conn, int $productId): ?array {
    if ($productId < 1) return null;
    ensureProductSizePriceSchema($conn);
    $stmt = $conn->prepare('SELECT 1 FROM product_size_config WHERE product_id=? AND customized=1 LIMIT 1');
    if (!$stmt) throw new RuntimeException('Could not read product size settings.');
    $stmt->bind_param('i', $productId);
    if (!$stmt->execute()) throw new RuntimeException('Could not load product size settings.');
    $customized = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$customized) return null;
    $stmt = $conn->prepare('SELECT size_label, price FROM product_size_list WHERE product_id=? ORDER BY sort_order, size_label');
    if (!$stmt) throw new RuntimeException('Could not read product sizes.');
    $stmt->bind_param('i', $productId);
    if (!$stmt->execute()) throw new RuntimeException('Could not load product sizes.');
    $list = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $list[] = ['size' => (string)$row['size_label'], 'price' => (float)$row['price']];
    }
    $stmt->close();
    return $list;
}

function printshopSizeOptions(string $productName, float $basePrice, ?mysqli $conn = null, int $productId = 0): array {
    if ($conn !== null && $productId > 0) {
        $custom = printshopCustomSizeList($conn, $productId);
        if ($custom !== null) return $custom;
    }
    // Compute the standard set first; apply only trusted database overrides below.
    $defaultOptions = printshopDefaultSizeOptions($productName, $basePrice);
    if ($conn === null || $productId < 1 || !$defaultOptions) return $defaultOptions;
    $overrides = printshopSizePriceOverrides($conn, $productId);
    foreach ($defaultOptions as &$option) {
        if (array_key_exists($option['size'], $overrides)) {
            $option['price'] = $overrides[$option['size']];
        }
    }
    unset($option);
    return $defaultOptions;
}

function printshopDefaultSizeOptions(string $productName, float $basePrice): array {
    // Use explicit package prices independently of generic size multipliers.
    $fixed = printshopFixedOptionPrices()[printshopCanonicalPriceName($productName)] ?? null;
    if ($fixed !== null) {
        $options = [];
        foreach (printshopSizeRules($productName) as [$label]) {
            $options[] = ['size' => $label, 'price' => $fixed[$label]];
        }
        return $options;
    }
    // Fixed prices per print for Photo Printing. Polaroid is always PHP 5.00,
    // independently of the Admin product's base price. Other product pricing
    // continues using the original editable base-price + multiplier behavior.
    if (strtolower(trim($productName)) === 'photo printing') {
        $photoPrices = [
            '4R (4 × 6 in)' => 15.00,
            'Polaroid-style (3.5 × 4.2 in)' => 5.00,
            '5R (5 × 7 in)' => 25.00,
            '8R (8 × 10 in)' => 45.00,
            'A4' => 50.00,
        ];
        $options = [];
        foreach (printshopSizeRules($productName) as [$label]) {
            $options[] = ['size' => $label, 'price' => $photoPrices[$label]];
        }
        return $options;
    }
    $options = [];
    foreach (printshopSizeRules($productName) as [$label, $multiplier]) {
        $options[] = ['size' => $label, 'price' => round(max(0, $basePrice) * $multiplier, 2)];
    }
    return $options;
}

/** Recompute from catalog price; never trust a client-submitted amount. */
function printshopPriceForSize(string $name, float $basePrice, string $size, ?mysqli $conn = null, int $productId = 0): array {
    $options = printshopSizeOptions($name, $basePrice, $conn, $productId);
    if (!$options) {
        if ($size !== '') throw new InvalidArgumentException('This product does not offer size selection.');
        return ['size' => '', 'price' => round($basePrice, 2)];
    }
    foreach ($options as $option) {
        if ($size === $option['size']) return $option;
    }
    throw new InvalidArgumentException('Please choose a valid size for ' . $name . '.');
}
