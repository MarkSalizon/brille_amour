<?php
require_once __DIR__ . "/material_inventory.php";

function ensureCatalogSchema(mysqli $conn): void {
    static $ready = false;

    if ($ready) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NULL,
            inventory_id INT NULL,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            image VARCHAR(255) NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NOT NULL DEFAULT 'piece',
            priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_products_category (category_id),
            INDEX idx_products_inventory (inventory_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensureTableColumn($conn, 'orders', 'product_id', "ALTER TABLE orders ADD COLUMN product_id INT NULL AFTER order_type");
    ensureTableColumn($conn, 'orders', 'pickup_time', "ALTER TABLE orders ADD COLUMN pickup_time TIME NULL AFTER due_date");
    ensureTableColumn($conn, 'orders', 'image', "ALTER TABLE orders ADD COLUMN image VARCHAR(255) NULL AFTER created_by");
    ensureTableColumn($conn, 'products', 'image', "ALTER TABLE products ADD COLUMN image VARCHAR(255) NULL AFTER description");
    ensureTableColumn($conn, 'products', 'inventory_id', "ALTER TABLE products ADD COLUMN inventory_id INT NULL AFTER category_id");
    ensureTableColumn($conn, 'products', 'sort_order', "ALTER TABLE products ADD COLUMN sort_order INT NOT NULL DEFAULT 999 AFTER unit");
    ensureTableColumn($conn, 'products', 'priority', "ALTER TABLE products ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER unit");

    seedCatalogCategories($conn);
    migrateLegacyMugsProduct($conn);
    migrateLegacyShirtProduct($conn);
    migrateLegacyOrderImageColumn($conn);
    seedDefaultProducts($conn);
    applyPrintingShopCatalogUpdate($conn);
    applyPrintingShopStarterPrices($conn);
    archiveDiscontinuedCustomizedShirts($conn);
    archiveDiscontinuedDocumentServices($conn);
    applyCombinedPrintingProductsAndPrices($conn);
    ensureEveryProductHasInventoryLink($conn);
    require_once __DIR__ . '/preorders.php';
    ensurePreorderSchema($conn);
    ensurePrintshopMaterialSchema($conn);
    applyMugPriceAndCatalogNames($conn);
    ensureDocumentPrintingProduct($conn);

    $ready = true;
}

function ensureTableColumn(mysqli $conn, string $table, string $column, string $alterSql): void {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $exists = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    if ($exists && $exists->num_rows === 0) {
        $conn->query($alterSql);
    }
}

function tableColumnExists(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $exists = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return (bool)($exists && $exists->num_rows > 0);
}

function seedCatalogCategories(mysqli $conn): void {
    static $seeded = false;

    if ($seeded) {
        return;
    }

    $defaultCategories = [
        ['name' => 'Large Format & Signage', 'description' => 'Tarpaulins, posters and rigid printed signs'],
        ['name' => 'Business & Promotional Prints', 'description' => 'Business cards, brochures, flyers, stickers and invitations'],
        ['name' => 'Apparel & Custom Merchandise', 'description' => 'Printed shirts and personalized merchandise'],
        ['name' => 'Photo & Document Services', 'description' => 'Photo, identification and document printing services'],
    ];

    $select = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $insert = $conn->prepare("INSERT INTO categories (name, description, status) VALUES (?, ?, 'active')");

    foreach ($defaultCategories as $category) {
        $name = $category['name'];
        $description = $category['description'];

        $select->bind_param('s', $name);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc();

        if (!$existing) {
            $insert->bind_param('ss', $name, $description);
            $insert->execute();
        }
    }

    $select->close();
    $insert->close();

    $seeded = true;
}

/**
 * One-time starting-price setup for active print-shop products that still have
 * no price. Never replace existing positive prices or later Admin edits.
 * Prices below are editable starting amounts in Philippine pesos, not verified
 * market quotes or a size/finish-dependent pricing engine.
 */
function applyPrintingShopStarterPrices(mysqli $conn): void {
    $version = 'printing_shop_starter_prices_2026_09';
    // The printing catalog migration creates this version-tracking table first.
    $check = $conn->prepare('SELECT version FROM catalog_migrations WHERE version = ? LIMIT 1');
    $check->bind_param('s', $version);
    $check->execute();
    $applied = (bool)$check->get_result()->fetch_assoc();
    $check->close();
    if ($applied) return;

    // Per product unit shown in Admin > Products (e.g. page / set / piece).
    $startingPrices = [
        'Tarpaulin Printing' => 120.00,
        'Sintra Board' => 250.00,
        'Posters' => 80.00,
        'Customized Mugs' => 180.00,
        'Acrylic Keychains' => 65.00,
        'Button Pins' => 35.00,
        'Ref Magnets' => 55.00,
        'Bookmarks' => 25.00,
        'Business Cards' => 290.00,
        'Flyers & Brochures' => 175.00,
        'Flyers/Brochure' => 175.00,
        'Stickers & Labels' => 107.00,
        'Stickers/Labels' => 107.00,
        'Invitations' => 35.00,
        'Photo Printing' => 15.00,
        'ID Picture Printing' => 45.00,
        'Document Printing' => 5.00,
        'Lamination' => 30.00,
        'PVC ID Cards' => 100.00,
    ];

    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE products SET unit_price = ?
            WHERE LOWER(TRIM(name)) = LOWER(?) AND status = 'active'
              AND (unit_price IS NULL OR unit_price <= 0)");
        if (!$update) throw new RuntimeException('Unable to prepare product price update.');
        foreach ($startingPrices as $name => $price) {
            $update->bind_param('ds', $price, $name);
            if (!$update->execute()) throw new RuntimeException('Unable to update product price.');
        }
        $update->close();
        $mark = $conn->prepare('INSERT INTO catalog_migrations (version) VALUES (?)');
        if (!$mark) throw new RuntimeException('Unable to prepare product price migration.');
        $mark->bind_param('s', $version);
        if (!$mark->execute()) throw new RuntimeException('Unable to mark product price migration.');
        $mark->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Retire the shirt product from the customer catalog without deleting existing
 * orders, product IDs, inventory records, or material transaction history.
 * Run only once so subsequent Admin archive/restore decisions stay intact.
 */
function archiveDiscontinuedCustomizedShirts(mysqli $conn): void {
    $version = 'archive_customized_shirts_2026_09';
    $check = $conn->prepare('SELECT version FROM catalog_migrations WHERE version = ? LIMIT 1');
    if (!$check) throw new RuntimeException('Unable to check shirt catalog migration.');
    $check->bind_param('s', $version);
    if (!$check->execute()) throw new RuntimeException('Unable to check shirt catalog migration.');
    $alreadyApplied = (bool)$check->get_result()->fetch_assoc();
    $check->close();
    if ($alreadyApplied) return;

    $conn->begin_transaction();
    try {
        $archive = $conn->prepare("UPDATE products SET status = 'archived'
            WHERE status = 'active'
              AND LOWER(TRIM(name)) IN ('customized shirts', 'custom t-shirts', 'custom shirts', 'customized t-shirts')");
        if (!$archive || !$archive->execute()) {
            throw new RuntimeException('Unable to archive the discontinued shirt product.');
        }
        $archive->close();

        $mark = $conn->prepare('INSERT INTO catalog_migrations (version) VALUES (?)');
        if (!$mark) throw new RuntimeException('Unable to record shirt catalog migration.');
        $mark->bind_param('s', $version);
        if (!$mark->execute()) throw new RuntimeException('Unable to record shirt catalog migration.');
        $mark->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * One-time removal of the three discontinued customer-shop items.
 * Archiving keeps linked historical orders and material records intact.
 * A version marker ensures Admin changes are not overwritten on later visits.
 */
function archiveDiscontinuedDocumentServices(mysqli $conn): void {
    $version = 'archive_document_lamination_pvc_2026_09';
    $check = $conn->prepare('SELECT version FROM catalog_migrations WHERE version = ? LIMIT 1');
    if (!$check) throw new RuntimeException('Unable to check discontinued product migration.');
    $check->bind_param('s', $version);
    if (!$check->execute()) throw new RuntimeException('Unable to check discontinued product migration.');
    $alreadyApplied = (bool)$check->get_result()->fetch_assoc();
    $check->close();
    if ($alreadyApplied) return;

    $conn->begin_transaction();
    try {
        $archived = $conn->query("UPDATE products SET status = 'archived'
            WHERE status = 'active'
              AND LOWER(TRIM(name)) IN ('document printing', 'lamination', 'pvc id cards')");
        if (!$archived) throw new RuntimeException('Unable to archive discontinued products.');

        $mark = $conn->prepare('INSERT INTO catalog_migrations (version) VALUES (?)');
        if (!$mark) throw new RuntimeException('Unable to record discontinued product migration.');
        $mark->bind_param('s', $version);
        if (!$mark->execute()) throw new RuntimeException('Unable to record discontinued product migration.');
        $mark->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * One-time (re)introduction of the Document Printing product:
 * PHP 3.00 per black & white page, PHP 5.00 per colored page (the two size options
 * live in includes/product_sizes.php). Restores the archived product if it exists,
 * so old orders keep their product ID. Runs once; later Admin edits are preserved.
 */
function ensureDocumentPrintingProduct(mysqli $conn): void {
    $version = 'document_printing_bw_color_2026_10';
    $check = $conn->prepare('SELECT version FROM catalog_migrations WHERE version = ? LIMIT 1');
    if (!$check) return;
    $check->bind_param('s', $version);
    $check->execute();
    $done = (bool)$check->get_result()->fetch_assoc();
    $check->close();
    if ($done) return;

    $description = 'Print your documents in black & white (PHP 3.00/page) or colored (PHP 5.00/page). Upload your file (PDF, Word, PowerPoint, Excel or image) and set the number of pages.';
    $conn->begin_transaction();
    try {
        $categoryId = null;
        $cat = $conn->query("SELECT id FROM categories WHERE LOWER(TRIM(name))='photo & document services' AND status='active' LIMIT 1");
        if ($cat && ($row = $cat->fetch_assoc())) $categoryId = (int)$row['id'];

        $find = $conn->query("SELECT id FROM products WHERE LOWER(TRIM(name))='document printing' ORDER BY (status='active') DESC, id ASC LIMIT 1");
        $existing = $find ? $find->fetch_assoc() : null;
        $price = 3.00; $unit = 'page'; $sort = 165;
        if ($existing) {
            $productId = (int)$existing['id'];
            $stmt = $conn->prepare("UPDATE products SET status='active', category_id=?, description=?, unit_price=?, unit=?, sort_order=? WHERE id=?");
            $stmt->bind_param('isdsii', $categoryId, $description, $price, $unit, $sort, $productId);
        } else {
            $name = 'Document Printing';
            $stmt = $conn->prepare("INSERT INTO products (category_id, name, description, unit_price, unit, sort_order, status) VALUES (?,?,?,?,?,?, 'active')");
            $stmt->bind_param('issdsi', $categoryId, $name, $description, $price, $unit, $sort);
        }
        if (!$stmt->execute()) throw new RuntimeException('Unable to save the Document Printing product.');
        if (!$existing) $productId = (int)$conn->insert_id;
        $stmt->close();

        // Drop stale per-size price overrides from the old A4/Letter/Legal/A3 options.
        $hasOverrides = $conn->query("SHOW TABLES LIKE 'product_size_prices'");
        if ($hasOverrides && $hasOverrides->num_rows) {
            $del = $conn->prepare('DELETE FROM product_size_prices WHERE product_id=?');
            $del->bind_param('i', $productId); $del->execute(); $del->close();
        }

        // Material link: 1 sheet of A4 paper per page, so the product is orderable
        // while paper stock lasts. Admin can add ink usage under Inventory/Products.
        $hasRecipes = $conn->query("SHOW TABLES LIKE 'product_materials'");
        if ($hasRecipes && $hasRecipes->num_rows) {
            $inv = $conn->query("SELECT id FROM inventory WHERE sku='PAPER-A4-80' LIMIT 1");
            if ($inv && ($paper = $inv->fetch_assoc())) {
                $paperId = (int)$paper['id']; $qty = 1.00;
                $rec = $conn->prepare('INSERT INTO product_materials (product_id, inventory_id, qty_per_unit) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty_per_unit=VALUES(qty_per_unit)');
                $rec->bind_param('iid', $productId, $paperId, $qty); $rec->execute(); $rec->close();
            }
        }

        $mark = $conn->prepare('INSERT INTO catalog_migrations (version) VALUES (?)');
        $mark->bind_param('s', $version);
        if (!$mark->execute()) throw new RuntimeException('Unable to record the Document Printing migration.');
        $mark->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** Merge products without deleting historical orders or re-applying prices on every request. */
function applyCombinedPrintingProductsAndPrices(mysqli $conn): void {
    $version = 'combined_print_pricing_2026_09_25';
    $check = $conn->prepare('SELECT version FROM catalog_migrations WHERE version=? LIMIT 1');
    if (!$check) throw new RuntimeException('Unable to prepare pricing migration.');
    $check->bind_param('s', $version);
    $check->execute(); $done = (bool)$check->get_result()->fetch_assoc(); $check->close();
    if ($done) return;

    $conn->begin_transaction();
    try {
        // Preserve old product IDs for previous orders. Only archive duplicate catalog listings.
        $merge = [
            'Flyers & Brochures' => ['flyers', 'brochures'],
            'Stickers & Labels' => ['stickers', 'labels'],
        ];
        $materialsTable = $conn->query("SHOW TABLES LIKE 'product_materials'");
        $hasMaterialRecipes = $materialsTable && $materialsTable->num_rows > 0;
        foreach ($merge as $mergedName => $legacyNames) {
            $lookupName = $mergedName === 'Flyers & Brochures' ? 'Flyers/Brochure' : 'Stickers/Labels';
            $get = $conn->prepare('SELECT id FROM products WHERE LOWER(TRIM(name)) IN (LOWER(?),LOWER(?)) ORDER BY CASE WHEN LOWER(TRIM(name))=LOWER(?) THEN 0 ELSE 1 END, id LIMIT 1');
            $get->bind_param('sss', $mergedName, $lookupName, $mergedName);
            $get->execute(); $targetId = (int)($get->get_result()->fetch_assoc()['id'] ?? 0); $get->close();
            if (!$targetId) throw new RuntimeException('Missing catalog entry for ' . $mergedName);
            foreach ($legacyNames as $legacyName) {
                $find = $conn->prepare('SELECT id FROM products WHERE LOWER(TRIM(name))=? AND id<>? ORDER BY id');
                $find->bind_param('si', $legacyName, $targetId);
                $find->execute(); $oldRows = $find->get_result()->fetch_all(MYSQLI_ASSOC); $find->close();
                foreach ($oldRows as $old) {
                    $oldId = (int)$old['id'];
                    if ($hasMaterialRecipes) {
                        // Copy existing admin-edited material recipe without overwriting target overrides.
                        $copy = $conn->prepare('INSERT IGNORE INTO product_materials(product_id,inventory_id,qty_per_unit) SELECT ?,inventory_id,qty_per_unit FROM product_materials WHERE product_id=?');
                        $copy->bind_param('ii', $targetId, $oldId);
                        if (!$copy->execute()) throw new RuntimeException('Unable to carry over product materials.');
                        $copy->close();
                    }
                    $archive = $conn->prepare("UPDATE products SET status='archived' WHERE id=?");
                    $archive->bind_param('i', $oldId);
                    if (!$archive->execute()) throw new RuntimeException('Unable to archive duplicate product.');
                    $archive->close();
                }
            }
        }
        $prices = [
            'Tarpaulin Printing' => 120.00,     // 2×3 ft, ₱20 per sq ft
            'Business Cards' => 290.00,        // 100-copy set
            'Flyers & Brochures' => 175.00,    // A6 flyer, 100-copy starter option
            'Flyers/Brochure' => 175.00,
            'Stickers & Labels' => 107.00,     // 2×2 in, 80-copy bundle
            'Stickers/Labels' => 107.00,
            'ID Picture Printing' => 45.00,    // combined picture package
        ];
        $setPrice = $conn->prepare("UPDATE products SET unit_price=?, unit=CASE WHEN LOWER(TRIM(name)) IN ('business cards','id picture printing') THEN 'set' ELSE unit END WHERE LOWER(TRIM(name))=LOWER(?) AND status='active'");
        foreach ($prices as $name => $price) {
            $setPrice->bind_param('ds', $price, $name);
            if (!$setPrice->execute()) throw new RuntimeException('Could not save new product prices.');
        }
        $setPrice->close();
        $mark=$conn->prepare('INSERT INTO catalog_migrations(version) VALUES (?)');
        $mark->bind_param('s',$version);
        if (!$mark->execute()) throw new RuntimeException('Unable to record pricing migration.');
        $mark->close();
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}


/**
 * Catalog change requested on 2026-09-25. Run once only: preserve product IDs,
 * customer order history, cart references and existing material recipes.
 * Price is per mug, including both currently offered mug sizes.
 */
function applyMugPriceAndCatalogNames(mysqli $conn): void {
    $version = 'mugs_70_remove_posters_rename_prints_2026_09_25';
    $check = $conn->prepare('SELECT version FROM catalog_migrations WHERE version=? LIMIT 1');
    if (!$check) throw new RuntimeException('Unable to verify the product catalog update.');
    $check->bind_param('s', $version);
    if (!$check->execute()) throw new RuntimeException('Unable to verify the product catalog update.');
    $applied = (bool)$check->get_result()->fetch_assoc();
    $check->close();
    if ($applied) return;

    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE products SET unit_price=70.00, unit='piece'
            WHERE LOWER(TRIM(name)) IN ('customized mugs','mugs') AND status='active'");
        if (!$update || !$update->execute()) throw new RuntimeException('Unable to set mug price.');
        $update->close();

        $archive = $conn->query("UPDATE products SET status='archived' WHERE LOWER(TRIM(name))='posters' AND status='active'");
        if (!$archive) throw new RuntimeException('Unable to archive posters.');

        $renames = ['Flyers & Brochures' => 'Flyers/Brochure', 'Stickers & Labels' => 'Stickers/Labels'];
        $find = $conn->prepare('SELECT id FROM products WHERE LOWER(TRIM(name))=LOWER(?) LIMIT 1');
        $rename = $conn->prepare('UPDATE products SET name=? WHERE LOWER(TRIM(name))=LOWER(?)');
        $archiveDuplicate = $conn->prepare("UPDATE products SET status='archived' WHERE LOWER(TRIM(name))=LOWER(?)");
        $copyMaterials = $conn->prepare('INSERT IGNORE INTO product_materials(product_id,inventory_id,qty_per_unit) SELECT ?,inventory_id,qty_per_unit FROM product_materials WHERE product_id=?');
        if (!$find || !$rename || !$archiveDuplicate || !$copyMaterials) throw new RuntimeException('Unable to prepare product rename.');
        foreach ($renames as $oldName => $newName) {
            $find->bind_param('s', $newName);
            if (!$find->execute()) throw new RuntimeException('Unable to check product rename.');
            $alreadyNew = $find->get_result()->fetch_assoc();
            if ($alreadyNew) {
                // An existing new-name record takes precedence; retain archived
                // duplicate IDs so past orders still resolve correctly.
                $find->bind_param('s', $oldName);
                if (!$find->execute()) throw new RuntimeException('Unable to check old product.');
                $legacy = $find->get_result()->fetch_assoc();
                if ($legacy && (int)$legacy['id'] !== (int)$alreadyNew['id']) {
                    $newId = (int)$alreadyNew['id']; $oldId = (int)$legacy['id'];
                    $copyMaterials->bind_param('ii', $newId, $oldId);
                    if (!$copyMaterials->execute()) throw new RuntimeException('Unable to carry over material recipe.');
                    $archiveDuplicate->bind_param('s', $oldName);
                    if (!$archiveDuplicate->execute()) throw new RuntimeException('Unable to archive duplicate listing.');
                }
            } else {
                $rename->bind_param('ss', $newName, $oldName);
                if (!$rename->execute()) throw new RuntimeException('Unable to rename the product.');
            }
        }
        $find->close(); $rename->close(); $archiveDuplicate->close(); $copyMaterials->close();
        $mark = $conn->prepare('INSERT INTO catalog_migrations(version) VALUES(?)');
        if (!$mark) throw new RuntimeException('Unable to record the catalog change.');
        $mark->bind_param('s', $version);
        if (!$mark->execute()) throw new RuntimeException('Unable to record the catalog change.');
        $mark->close();
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

/** Keep existing Mugs price, stock link and product ID instead of creating a duplicate. */
function migrateLegacyMugsProduct(mysqli $conn): void {
    $existing = $conn->query("SELECT id FROM products WHERE LOWER(TRIM(name)) = 'customized mugs' LIMIT 1");
    if ($existing && $existing->num_rows > 0) return;
    $conn->query("UPDATE products SET name = 'Customized Mugs' WHERE LOWER(TRIM(name)) = 'mugs'");
}

/** Keep order and inventory relationships for an existing T-shirt product. */
function migrateLegacyShirtProduct(mysqli $conn): void {
    $existing = $conn->query("SELECT id FROM products WHERE LOWER(TRIM(name)) = 'customized shirts' LIMIT 1");
    if ($existing && $existing->num_rows > 0) return;
    $conn->query("UPDATE products SET name = 'Customized Shirts' WHERE LOWER(TRIM(name)) IN ('custom t-shirts', 'customized t-shirts', 'custom shirts')");
}

/**
 * One-time printing-shop catalog migration. Archive, never delete, old products;
 * order history and its product IDs remain intact. Once applied, Admin may
 * archive or restore individual products without being overridden on each load.
 */
function applyPrintingShopCatalogUpdate(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS catalog_migrations (
        version VARCHAR(64) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $version = 'printing_shop_catalog_2026_09';
    $check = $conn->prepare('SELECT version FROM catalog_migrations WHERE version = ? LIMIT 1');
    $check->bind_param('s', $version);
    $check->execute();
    $applied = $check->get_result()->fetch_assoc();
    $check->close();
    if ($applied) return;

    // The table rows above were just seeded. Do not remove existing order records
    // or change prices, linked inventory, and IDs for products that are retained.
    $categoryMap = [];
    $result = $conn->query("SELECT id, name FROM categories WHERE status = 'active'");
    while ($row = $result->fetch_assoc()) {
        $categoryMap[strtolower(trim((string)$row['name']))] = (int)$row['id'];
    }
    $assignments = [
        'tarpaulin printing' => 'large format & signage',
        'sintra board' => 'large format & signage',
        'posters' => 'large format & signage',
        'customized mugs' => 'apparel & custom merchandise',
        'acrylic keychains' => 'apparel & custom merchandise',
        'button pins' => 'apparel & custom merchandise',
        'ref magnets' => 'apparel & custom merchandise',
        'bookmarks' => 'apparel & custom merchandise',
        'business cards' => 'business & promotional prints',
        'flyers & brochures' => 'business & promotional prints',
        'flyers/brochure' => 'business & promotional prints',
        'stickers & labels' => 'business & promotional prints',
        'stickers/labels' => 'business & promotional prints',
        'invitations' => 'business & promotional prints',
        'photo printing' => 'photo & document services',
        'id picture printing' => 'photo & document services',
        'document printing' => 'photo & document services',
        'lamination' => 'photo & document services',
        'pvc id cards' => 'photo & document services',
    ];

    $conn->begin_transaction();
    try {
        $archiveSql = "UPDATE products SET status='archived'
            WHERE status='active' AND LOWER(TRIM(name)) NOT IN ('tarpaulin printing', 'sintra board', 'posters', 'customized mugs', 'acrylic keychains', 'button pins', 'ref magnets', 'bookmarks', 'business cards', 'flyers & brochures', 'flyers/brochure', 'stickers & labels', 'stickers/labels', 'invitations', 'photo printing', 'id picture printing', 'document printing', 'lamination', 'pvc id cards')";
        if (!$conn->query($archiveSql)) throw new RuntimeException($conn->error);

        // Show every item from the new requested catalog on first installation;
        // later Admin archive/restore choices are left untouched.
        $activateSql = "UPDATE products SET status='active' WHERE LOWER(TRIM(name)) IN ('tarpaulin printing', 'sintra board', 'posters', 'customized mugs', 'acrylic keychains', 'button pins', 'ref magnets', 'bookmarks', 'business cards', 'flyers & brochures', 'flyers/brochure', 'stickers & labels', 'stickers/labels', 'invitations', 'photo printing', 'id picture printing', 'document printing', 'lamination', 'pvc id cards')";
        if (!$conn->query($activateSql)) throw new RuntimeException($conn->error);

        $updateCategory = $conn->prepare('UPDATE products SET category_id = ? WHERE LOWER(TRIM(name)) = ?');
        foreach ($assignments as $productName => $categoryName) {
            $categoryId = $categoryMap[$categoryName] ?? null;
            if (!$categoryId) throw new RuntimeException('Missing printing category: ' . $categoryName);
            $updateCategory->bind_param('is', $categoryId, $productName);
            if (!$updateCategory->execute()) throw new RuntimeException($updateCategory->error);
        }
        $updateCategory->close();

        // Retire empty legacy filters; keep categories tied to an active product.
        $archiveCategories = "UPDATE categories c SET c.status='archived'
            WHERE LOWER(TRIM(c.name)) NOT IN ('large format & signage', 'business & promotional prints', 'apparel & custom merchandise', 'photo & document services')
            AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id=c.id AND p.status='active')";
        if (!$conn->query($archiveCategories)) throw new RuntimeException($conn->error);

        $save = $conn->prepare('INSERT INTO catalog_migrations (version) VALUES (?)');
        $save->bind_param('s', $version);
        if (!$save->execute()) throw new RuntimeException($save->error);
        $save->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function migrateLegacyOrderImageColumn(mysqli $conn): void {
    static $migrated = false;

    if ($migrated) {
        return;
    }

    if (tableColumnExists($conn, 'orders', 'product_image') && tableColumnExists($conn, 'orders', 'image')) {
        $conn->query("
            UPDATE orders
            SET image = COALESCE(NULLIF(image, ''), product_image)
            WHERE product_image IS NOT NULL
              AND product_image <> ''
              AND (image IS NULL OR image = '')
        ");
    }

    $migrated = true;
}

function getActiveCategoriesList(mysqli $conn): array {
    $rows = [];
    $res = $conn->query("SELECT id, name FROM categories WHERE status='active' ORDER BY name");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function seedDefaultProducts(mysqli $conn): void {
    static $seeded = false;
    if ($seeded) return;

    // Only bootstrap the initial catalog once. Otherwise a product removed or
    // renamed by Admin can reappear each time a customer opens the Shop.
    if (!$conn->query("CREATE TABLE IF NOT EXISTS catalog_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Could not verify initial product catalog.');
    }
    $seedKey = 'initial_catalog_seed_admin_managed_v1';
    $seedCheck = $conn->prepare('SELECT version FROM catalog_migrations WHERE version=? LIMIT 1');
    $seedCheck->bind_param('s', $seedKey);
    $seedCheck->execute();
    $alreadySeeded = (bool)$seedCheck->get_result()->fetch_assoc();
    $seedCheck->close();
    if ($alreadySeeded) { $seeded = true; return; }
    // Established printing-shop databases have already received their catalog.
    // Adopt it as-is instead of reviving discontinued or renamed products.
    $existingMigration = $conn->query("SELECT version FROM catalog_migrations WHERE version='printing_shop_catalog_2026_09' LIMIT 1");
    if ($existingMigration && $existingMigration->num_rows > 0) {
        $mark = $conn->prepare('INSERT IGNORE INTO catalog_migrations(version) VALUES(?)');
        $mark->bind_param('s', $seedKey);
        $mark->execute(); $mark->close();
        $seeded = true;
        return;
    }

    $categoryIds = [];
    $res = $conn->query("SELECT id, name FROM categories WHERE status='active'");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categoryIds[$row['name']] = (int)$row['id'];
        }
    }

    // New catalog entries start without an assumed price; Admin sets real prices.
    // Existing database prices are preserved in the UPDATE query below.
    $defaultProducts = [
        ['name' => 'Tarpaulin Printing', 'category' => 'Large Format & Signage', 'unit_price' => 0.00, 'sort_order' => 10, 'unit' => 'piece', 'description' => 'Custom tarpaulin banners for birthdays, business promos and events. Provide your width, height, artwork and eyelet requirements.'],
        ['name' => 'Sintra Board', 'category' => 'Large Format & Signage', 'unit_price' => 0.00, 'sort_order' => 20, 'unit' => 'piece', 'description' => 'Printed rigid PVC Sintra signboard for menus, business signs and displays. Specify your dimensions, thickness and print design.'],
        ['name' => 'Posters', 'category' => 'Large Format & Signage', 'unit_price' => 0.00, 'sort_order' => 30, 'unit' => 'piece', 'description' => 'Full-color promotional posters for events, shops and announcements. Specify dimensions, material and your print-ready artwork.'],
        ['name' => 'Customized Mugs', 'category' => 'Apparel & Custom Merchandise', 'unit_price' => 0.00, 'sort_order' => 50, 'unit' => 'piece', 'description' => 'Photo and name printing on personalized mugs. Send your design, text and quantity.'],
        ['name' => 'Acrylic Keychains', 'category' => 'Apparel & Custom Merchandise', 'unit_price' => 0.00, 'sort_order' => 60, 'unit' => 'piece', 'description' => 'Custom printed acrylic keychains with your photo, logo or name. Select shape, dimensions and quantity.'],
        ['name' => 'Button Pins', 'category' => 'Apparel & Custom Merchandise', 'unit_price' => 0.00, 'sort_order' => 70, 'unit' => 'piece', 'description' => 'Printed button pins for schools, events and branded giveaways. Specify pin size, artwork and quantity.'],
        ['name' => 'Ref Magnets', 'category' => 'Apparel & Custom Merchandise', 'unit_price' => 0.00, 'sort_order' => 80, 'unit' => 'piece', 'description' => 'Personalized refrigerator magnets with photos, logos and custom text. Specify size and quantity.'],
        ['name' => 'Bookmarks', 'category' => 'Apparel & Custom Merchandise', 'unit_price' => 0.00, 'sort_order' => 90, 'unit' => 'piece', 'description' => 'Full-color custom printed bookmarks with your preferred layout and finish. Specify dimensions and quantity.'],
        ['name' => 'Business Cards', 'category' => 'Business & Promotional Prints', 'unit_price' => 0.00, 'sort_order' => 100, 'unit' => 'piece', 'description' => 'Business card printing: 100 copies per set.'],
        ['name' => 'Flyers/Brochure', 'category' => 'Business & Promotional Prints', 'unit_price' => 0.00, 'sort_order' => 110, 'unit' => 'piece', 'description' => 'Choose flyer or brochure size and print bundle.'],
        ['name' => 'Stickers/Labels', 'category' => 'Business & Promotional Prints', 'unit_price' => 0.00, 'sort_order' => 130, 'unit' => 'piece', 'description' => 'Choose a sticker/label bundle or a single piece.'],
        ['name' => 'Invitations', 'category' => 'Business & Promotional Prints', 'unit_price' => 0.00, 'sort_order' => 150, 'unit' => 'piece', 'description' => 'Printed invitations for birthdays, weddings and celebrations. Provide event details, size and paper preference.'],
        ['name' => 'Photo Printing', 'category' => 'Photo & Document Services', 'unit_price' => 0.00, 'sort_order' => 160, 'unit' => 'piece', 'description' => 'Print your photos in your chosen photo paper size and finish. Upload the original high-resolution files.'],
        ['name' => 'ID Picture Printing', 'category' => 'Photo & Document Services', 'unit_price' => 0.00, 'sort_order' => 170, 'unit' => 'set', 'description' => 'One ID photo package: four 2×2, eight 1×1 and two passport prints.'],
    ];

    $select = $conn->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $insert = $conn->prepare("
        INSERT INTO products (category_id, name, description, unit_price, unit, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $update = $conn->prepare("
        UPDATE products
        SET category_id = COALESCE(category_id, ?),
            unit_price = CASE WHEN unit_price <= 0 THEN ? ELSE unit_price END,
            description = CASE WHEN description IS NULL OR TRIM(description) = '' OR description LIKE 'Imported from existing order history%' THEN ? ELSE description END,
            priority = COALESCE(NULLIF(priority, ''), 'normal'),
            sort_order = ?
        WHERE id = ?
    ");

    foreach ($defaultProducts as $product) {
        $name = $product['name'];
        $categoryId = $categoryIds[$product['category']] ?? null;
        $unitPrice = (float)$product['unit_price'];
        $sortOrder = $product['sort_order'];
        $description = $product['description'] ?? '';
        $unit = $product['unit'] ?? 'piece';

        $select->bind_param('s', $name);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc();
        // During upgrade, reuse the existing legacy product row rather than
        // creating a new ID and orphaning its recipe, old orders or saved carts.
        if (!$existing && isset(['Flyers/Brochure' => 1, 'Stickers/Labels' => 1][$name])) {
            $legacyName = $name === 'Flyers/Brochure' ? 'Flyers & Brochures' : 'Stickers & Labels';
            $select->bind_param('s', $legacyName);
            $select->execute();
            $existing = $select->get_result()->fetch_assoc();
        }

        if ($existing) {
            $productId = (int)$existing['id'];
            $update->bind_param('idsii', $categoryId, $unitPrice, $description, $sortOrder, $productId);
            $update->execute();
        } else {
            $insert->bind_param('issdsi', $categoryId, $name, $description, $unitPrice, $unit, $sortOrder);
            $insert->execute();
        }
    }

    $select->close();
    $insert->close();
    $update->close();

    $mark = $conn->prepare('INSERT IGNORE INTO catalog_migrations(version) VALUES(?)');
    $mark->bind_param('s', $seedKey);
    $mark->execute(); $mark->close();
    $seeded = true;
}

/**
 * Do not auto-create zero-stock finished-goods rows for print-on-demand products.
 * Existing inventory links remain in place and Admin may assign real materials.
 */
function ensureEveryProductHasInventoryLink(mysqli $conn): void {
    // Intentionally no-op: new printing products are made to order.
}

function buildProductInventorySku(mysqli $conn, int|string $productName, int $productId): string {
    $base = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', trim((string)$productName)));
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'PRODUCT';
    }

    $base = substr($base, 0, 30);
    $sku = 'PROD-' . $base . '-' . $productId;
    $candidate = $sku;
    $counter = 1;

    while (inventorySkuExists($conn, $candidate)) {
        $candidate = substr($sku, 0, 45) . '-' . $counter;
        $counter++;
    }

    return $candidate;
}

function inventorySkuExists(mysqli $conn, string $sku): bool {
    $stmt = $conn->prepare("SELECT id FROM inventory WHERE sku = ? LIMIT 1");
    $stmt->bind_param('s', $sku);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function getInventoryOptions(mysqli $conn): array {
    $rows = [];
    $res = $conn->query("SELECT id, name, quantity, unit FROM inventory WHERE status='active' ORDER BY name");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function getActiveProductsList(mysqli $conn): array {
    $rows = [];
    $res = $conn->query("
        SELECT
            p.*,
            c.name AS category_name,
            i.quantity AS inventory_quantity,
            i.name AS inventory_name,
            i.unit AS inventory_unit
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN inventory i ON i.id = p.inventory_id
        WHERE p.status='active'
        ORDER BY
            FIELD(p.priority, 'urgent', 'high', 'normal', 'low'),
            CASE WHEN p.sort_order > 0 AND p.sort_order < 999 THEN 0 ELSE 1 END,
            p.sort_order,
            c.name IS NULL,
            c.name,
            p.name
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function getProductCatalogMap(mysqli $conn): array {
    $map = [];

    $products = getActiveProductsList($conn);
    $availability = printshopProductAvailabilityMap($conn, $products);
    foreach ($products as $product) {
        $id = (int)$product['id'];
        $map[$id] = [
            'id' => $id,
            'category_id' => (int)($product['category_id'] ?? 0),
            'name' => $product['name'],
            'description' => $product['description'] ?? '',
            'unit_price' => (float)$product['unit_price'],
            'unit' => $product['unit'] ?? 'piece',
            'priority' => $product['priority'] ?? 'normal',
            'status' => $product['status'] ?? 'active',
            'inventory_id' => isset($product['inventory_id']) ? (int)$product['inventory_id'] : 0,
            'available' => (bool)$availability[$id]['available'],
            'max_order_qty' => (int)$availability[$id]['max_order_qty'],
            'inventory_name' => $product['inventory_name'] ?? '',
            'inventory_quantity' => isset($product['inventory_quantity']) ? (float)$product['inventory_quantity'] : null,
            'inventory_unit' => $product['inventory_unit'] ?? ($product['unit'] ?? 'piece'),
        ];
    }

    return $map;
}

function getProductById(mysqli $conn, int $productId): ?array {
    if ($productId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT p.*, i.quantity AS inventory_quantity, i.name AS inventory_name, i.unit AS inventory_unit
        FROM products p
        LEFT JOIN inventory i ON i.id = p.inventory_id
        WHERE p.id = ? AND p.status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

/** Existing call sites share this helper: use multi-material recipes, never deduct a finished product. */
function syncOrderProductInventory(mysqli $conn, int $orderId, ?int $productId, int $effectiveQty, int $userId, string $reference): array {
    return syncPrintshopOrderMaterials($conn, $orderId, $productId, $effectiveQty, $userId, $reference);
}

/** Atomic manual movement helper; never permit negative stock under concurrent checkouts. */
function applyInventoryDelta(mysqli $conn, int $inventoryId, float $qty, string $reference, string $type, string $notes, int $userId): void {
    if ($inventoryId <= 0 || !is_finite($qty) || $qty <= 0 || !in_array($type,['in','out'],true))
        throw new RuntimeException('Enter a valid stock movement.');
    $qty=round($qty,2);
    if ($qty<=0) throw new RuntimeException('Minimum stock movement is 0.01.');
    if ($type==='out') {
        $update=$conn->prepare("UPDATE inventory SET quantity=quantity-? WHERE id=? AND status='active' AND quantity>=?");
        $update->bind_param('did',$qty,$inventoryId,$qty);
    } else {
        $update=$conn->prepare("UPDATE inventory SET quantity=quantity+? WHERE id=? AND status='active'");
        $update->bind_param('di',$qty,$inventoryId);
    }
    if (!$update || !$update->execute() || $update->affected_rows!==1) {
        if ($update) $update->close();
        throw new RuntimeException($type==='out' ? 'Insufficient stock or material is unavailable.' : 'Inventory item is not available.');
    }
    $update->close();
    $tx=$conn->prepare('INSERT INTO inventory_transactions (inventory_id,type,quantity,reference,notes,created_by) VALUES (?,?,?,?,?,?)');
    $actor=$userId>0?$userId:null;
    $tx->bind_param('isdssi',$inventoryId,$type,$qty,$reference,$notes,$actor);
    if (!$tx->execute()) throw new RuntimeException('Could not record inventory movement.');
    $tx->close();
}
