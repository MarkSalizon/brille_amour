<?php
/** Raw-material recipes. Existing finished-goods rows and order history are retained. */
function printshopMaterialTemplates(): array {
    // Initial quantities are deliberately zero: the shop must enter its ACTUAL stock.
    return [
        'PAPER-A4-80'=>['Bond Paper A4 (80 gsm)','Paper & Card Stock','sheet'],
        'PAPER-LTR-80'=>['Bond Paper Letter (80 gsm)','Paper & Card Stock','sheet'],
        'PAPER-LGL-80'=>['Bond Paper Legal (80 gsm)','Paper & Card Stock','sheet'],
        'PAPER-A3-80'=>['Bond Paper A3 (80 gsm)','Paper & Card Stock','sheet'],
        'PAPER-A4-200'=>['Card Stock A4 (200 gsm)','Paper & Card Stock','sheet'],
        'PAPER-A3-200'=>['Card Stock A3 (200 gsm)','Paper & Card Stock','sheet'],
        'PAPER-PHOTO-4X6'=>['Glossy Photo Paper 4x6','Paper & Card Stock','sheet'],
        'PAPER-PHOTO-A4'=>['Glossy Photo Paper A4','Paper & Card Stock','sheet'],
        'PAPER-STICKER-A4'=>['Printable Sticker Paper A4','Paper & Card Stock','sheet'],
        'PAPER-SUBLIMATION-A4'=>['Sublimation Transfer Paper A4','Paper & Card Stock','sheet'],
        'INK-C'=>['Printing Ink - Cyan','Inks & Toners','ml'],
        'INK-M'=>['Printing Ink - Magenta','Inks & Toners','ml'],
        'INK-Y'=>['Printing Ink - Yellow','Inks & Toners','ml'],
        'INK-K'=>['Printing Ink - Black','Inks & Toners','ml'],
        'INK-SUB-C'=>['Sublimation Ink - Cyan','Inks & Toners','ml'],
        'INK-SUB-M'=>['Sublimation Ink - Magenta','Inks & Toners','ml'],
        'INK-SUB-Y'=>['Sublimation Ink - Yellow','Inks & Toners','ml'],
        'INK-SUB-K'=>['Sublimation Ink - Black','Inks & Toners','ml'],
        'TARP-SQM'=>['Printable Tarpaulin (1 sq m)','Large Format Materials','sq m'],
        'VINYL-SQM'=>['Printable Vinyl (1 sq m)','Large Format Materials','sq m'],
        'SINTRA-2X4'=>['Sintra Board 2 x 4 ft','Large Format Materials','sheet'],
        'LAM-FILM-A4'=>['Lamination Film A4 (pair)','Lamination & ID','pair'],
        'LAM-FILM-ID'=>['Lamination Film ID Size (pair)','Lamination & ID','pair'],
        'PVC-BLANK'=>['Blank PVC ID Card','Lamination & ID','pc'],
        'SHIRT-BLANK'=>['Blank T-Shirt','Apparel & Souvenir Blanks','pc'],
        'TRANSFER-A4'=>['Heat Transfer Film A4','Apparel & Souvenir Blanks','sheet'],
        'MUG-BLANK'=>['Blank Sublimation Mug','Apparel & Souvenir Blanks','pc'],
        'KEYCHAIN-BLANK'=>['Blank Acrylic Keychain','Apparel & Souvenir Blanks','pc'],
        'MAGNET-BLANK'=>['Blank Ref Magnet','Apparel & Souvenir Blanks','pc'],
        'PIN-BLANK'=>['Blank Button Pin Set','Apparel & Souvenir Blanks','set'],
        'BOOKMARK-BLANK'=>['Blank Bookmark Card','Apparel & Souvenir Blanks','pc'],
    ];
}
function printshopDefaultRecipes(): array {
    // STARTING estimates per catalog unit; admin must calibrate paper size, ink usage and area.
    $cmyk = ['INK-C'=>1,'INK-M'=>1,'INK-Y'=>1,'INK-K'=>1];
    $sub = ['INK-SUB-C'=>1,'INK-SUB-M'=>1,'INK-SUB-Y'=>1,'INK-SUB-K'=>1];
    return [
        'tarpaulin printing'=>['TARP-SQM'=>1]+$cmyk,
        'sintra board'=>['SINTRA-2X4'=>1,'VINYL-SQM'=>1]+$cmyk,
        'posters'=>['PAPER-A3-200'=>1]+$cmyk,
        'business cards'=>['PAPER-A4-200'=>1]+$cmyk,
        'flyers'=>['PAPER-A4-80'=>1]+$cmyk,
        'flyers & brochures'=>['PAPER-A4-80'=>1]+$cmyk,
        'brochures'=>['PAPER-A4-200'=>1]+$cmyk,
        'stickers'=>['PAPER-STICKER-A4'=>1]+$cmyk,
        'stickers & labels'=>['PAPER-STICKER-A4'=>1]+$cmyk,
        'labels'=>['PAPER-STICKER-A4'=>1]+$cmyk,
        'invitations'=>['PAPER-A4-200'=>1]+$cmyk,
        'customized shirts'=>['SHIRT-BLANK'=>1,'TRANSFER-A4'=>1]+$sub,
        'mugs'=>['MUG-BLANK'=>1,'PAPER-SUBLIMATION-A4'=>1]+$sub,
        'flyers/brochure'=>['PAPER-A4-80'=>1]+$cmyk,
        'stickers/labels'=>['PAPER-STICKER-A4'=>1]+$cmyk,
        'customized mugs'=>['MUG-BLANK'=>1,'PAPER-SUBLIMATION-A4'=>1]+$sub,
        'acrylic keychains'=>['KEYCHAIN-BLANK'=>1,'PAPER-SUBLIMATION-A4'=>1]+$sub,
        'button pins'=>['PIN-BLANK'=>1,'PAPER-A4-200'=>1]+$cmyk,
        'ref magnets'=>['MAGNET-BLANK'=>1,'PAPER-A4-200'=>1]+$cmyk,
        'bookmarks'=>['BOOKMARK-BLANK'=>1]+$cmyk,
        'photo printing'=>['PAPER-PHOTO-4X6'=>1]+$cmyk,
        'id picture printing'=>['PAPER-PHOTO-4X6'=>1]+$cmyk,
        'document printing'=>['PAPER-A4-80'=>1,'INK-K'=>1],
        'lamination'=>['LAM-FILM-A4'=>1],
        'pvc id cards'=>['PVC-BLANK'=>1]+$cmyk,
    ];
}
function ensurePrintshopMaterialSchema(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;
    $sqls = [
        "CREATE TABLE IF NOT EXISTS product_materials (product_id INT NOT NULL, inventory_id INT NOT NULL, qty_per_unit DECIMAL(10,2) NOT NULL, PRIMARY KEY(product_id,inventory_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS material_order_baseline (order_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS material_inventory_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($sqls as $sql) if (!$conn->query($sql)) throw new RuntimeException($conn->error);
    $col = $conn->query("SHOW COLUMNS FROM inventory LIKE 'material_group'");
    if ($col && !$col->num_rows && !$conn->query("ALTER TABLE inventory ADD COLUMN material_group VARCHAR(70) NOT NULL DEFAULT '' AFTER name")) throw new RuntimeException($conn->error);
    // sq m and measured inks/films may need two decimal places; old schema used INT.
    $stockCol=$conn->query("SHOW COLUMNS FROM inventory LIKE 'quantity'");
    $stockDef=$stockCol ? $stockCol->fetch_assoc() : null;
    if ($stockDef && stripos((string)$stockDef['Type'],'decimal(')!==0
        && !$conn->query('ALTER TABLE inventory MODIFY quantity DECIMAL(12,2) NOT NULL DEFAULT 0')) throw new RuntimeException($conn->error);
    $done = $conn->query("SELECT version FROM material_inventory_migrations WHERE version='printshop_materials_v1'");
    if (!$done || !$done->num_rows) {
        // Preserve already-submitted orders: do not retroactively issue materials for old order IDs.
        $conn->begin_transaction();
        try {
            if (!$conn->query('INSERT IGNORE INTO material_order_baseline (order_id) SELECT id FROM orders')) throw new RuntimeException($conn->error);
            $insert = $conn->prepare("INSERT INTO inventory (name,material_group,sku,description,quantity,unit,reorder_level,unit_cost,status) VALUES (?,?,?,'Print-shop material. Enter actual opening stock before accepting orders.',0,?,10,0,'active')");
            $skuMap = [];
            foreach (printshopMaterialTemplates() as $sku => [$name,$group,$unit]) {
                $find=$conn->prepare('SELECT id FROM inventory WHERE sku=? LIMIT 1');
                $find->bind_param('s',$sku); $find->execute(); $id=(int)($find->get_result()->fetch_assoc()['id']??0); $find->close();
                if (!$id) { $insert->bind_param('ssss',$name,$group,$sku,$unit); if (!$insert->execute()) throw new RuntimeException($insert->error); $id=(int)$conn->insert_id; }
                $skuMap[$sku]=$id;
            }
            $insert->close();
            $productRows=$conn->query("SELECT id,name FROM products WHERE status='active'");
            if (!$productRows) throw new RuntimeException($conn->error);
            $recipeInsert=$conn->prepare('INSERT INTO product_materials (product_id,inventory_id,qty_per_unit) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty_per_unit=VALUES(qty_per_unit)');
            $recipes=printshopDefaultRecipes();
            while ($product=$productRows->fetch_assoc()) {
                $pid=(int)$product['id']; $name=strtolower(trim((string)$product['name']));
                foreach (($recipes[$name]??[]) as $sku=>$qty) {
                    $iid=$skuMap[$sku]; $amount=(float)$qty;
                    $recipeInsert->bind_param('iid',$pid,$iid,$amount);
                    if (!$recipeInsert->execute()) throw new RuntimeException($recipeInsert->error);
                }
            }
            $recipeInsert->close();
            // Legacy single-inventory links are finished-goods links. New recipes supersede
            // them; keep their rows and transaction history for audit instead of deleting.
            if (!$conn->query("UPDATE products SET inventory_id=NULL WHERE status='active'")) throw new RuntimeException($conn->error);
            if (!$conn->query("UPDATE inventory SET status='archived' WHERE status='active' AND material_group='' AND sku REGEXP '^(PRT-|CRA-|BST-|EVT-|PROD-)'")) throw new RuntimeException($conn->error);
            if (!$conn->query("INSERT INTO material_inventory_migrations(version) VALUES('printshop_materials_v1')")) throw new RuntimeException($conn->error);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
    repairMissingPrintshopRecipes($conn);
    $ready=true;
}
/** Backfill known catalog products only; preserve all admin recipes and stock quantities. */
function repairMissingPrintshopRecipes(mysqli $conn): void {
    $products = $conn->query("SELECT p.id,p.name FROM products p WHERE p.status='active'
        AND NOT EXISTS (SELECT 1 FROM product_materials pm WHERE pm.product_id=p.id)");
    if (!$products) throw new RuntimeException('Unable to check product material links.');
    $recipes = printshopDefaultRecipes();
    $materials = [];
    $rows = $conn->query('SELECT id,sku FROM inventory ORDER BY id');
    if (!$rows) throw new RuntimeException('Unable to read inventory material links.');
    while ($row = $rows->fetch_assoc()) {
        if (!isset($materials[$row['sku']])) $materials[$row['sku']] = (int)$row['id'];
    }
    while ($product = $products->fetch_assoc()) {
        $recipe = $recipes[strtolower(trim((string)$product['name']))] ?? [];
        if (!$recipe || array_diff(array_keys($recipe), array_keys($materials))) continue;
        $conn->begin_transaction();
        try {
            $pid = (int)$product['id'];
            $lock = $conn->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');
            $lock->bind_param('i', $pid); $lock->execute(); $lock->get_result(); $lock->close();
            $existing = $conn->query("SELECT product_id FROM product_materials WHERE product_id=$pid");
            if (!$existing) throw new RuntimeException('Unable to verify material recipe.');
            if (!$existing->num_rows) {
                $insert = $conn->prepare('INSERT INTO product_materials(product_id,inventory_id,qty_per_unit) VALUES(?,?,?)');
                foreach ($recipe as $sku => $qty) {
                    $iid = $materials[$sku]; $amount = (float)$qty;
                    $insert->bind_param('iid', $pid, $iid, $amount);
                    if (!$insert->execute()) throw new RuntimeException('Unable to restore product material links.');
                }
                $insert->close();
            }
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}
function printshopProductMaterials(mysqli $conn, int $productId): array {
    ensurePrintshopMaterialSchema($conn);
    $st=$conn->prepare("SELECT pm.inventory_id,pm.qty_per_unit,i.name,i.unit,i.quantity,i.status FROM product_materials pm JOIN inventory i ON i.id=pm.inventory_id WHERE pm.product_id=? ORDER BY i.name");
    $st->bind_param('i',$productId); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    return $rows;
}
/** Call inside the caller's order transaction. Uses locked inventory rows, idempotent per order + material. */
function syncPrintshopOrderMaterials(mysqli $conn, int $orderId, ?int $productId, int $qty, int $userId, string $reference): array {
    $marker='AUTO_MATERIAL_STOCK';
    try {
        ensurePrintshopMaterialSchema($conn); // first-run setup must happen BEFORE caller begins transaction
        $baseline=$conn->prepare('SELECT order_id FROM material_order_baseline WHERE order_id=? LIMIT 1');
        $baseline->bind_param('i',$orderId); $baseline->execute(); $isLegacy=(bool)$baseline->get_result()->fetch_assoc(); $baseline->close();
        if ($isLegacy) return ['ok'=>true];
        $existing=[];
        $st=$conn->prepare('SELECT id,inventory_id,quantity_used,item_name,unit FROM order_items WHERE order_id=? AND notes=? FOR UPDATE');
        $st->bind_param('is',$orderId,$marker); $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $existing[(int)$row['inventory_id']]=$row;
        $st->close();
        $target=[];
        if ($productId && $qty>0) {
            foreach (printshopProductMaterials($conn,$productId) as $row) $target[(int)$row['inventory_id']]=['qty'=>round($qty*(float)$row['qty_per_unit'],2),'name'=>$row['name'],'unit'=>$row['unit'],'status'=>$row['status']];
            if (!$target) throw new RuntimeException('This product has no material recipe. Please configure it in Admin > Inventory before accepting orders.');
        }
        $ids=array_unique(array_merge(array_keys($existing),array_keys($target)));
        sort($ids,SORT_NUMERIC); // fixed locking order prevents deadlocks across multiple products
        foreach ($ids as $iid) {
            $old=(float)($existing[$iid]['quantity_used']??0); $new=(float)($target[$iid]['qty']??0);
            $delta=round($new-$old,2);
            if (abs($delta)<0.001) continue;
            $lock=$conn->prepare('SELECT name,quantity,status FROM inventory WHERE id=? FOR UPDATE');
            $lock->bind_param('i',$iid); $lock->execute(); $item=$lock->get_result()->fetch_assoc(); $lock->close();
            if (!$item) throw new RuntimeException('Order material is missing from inventory.');
            if ($delta>0 && $item['status']!=='active') throw new RuntimeException('Required material is archived: '.$item['name']);
            if ($delta>0 && (float)$item['quantity']+0.0001<$delta) throw new RuntimeException('Insufficient material: '.$item['name'].' (need '.rtrim(rtrim(number_format($delta,2,'.',''),'0'),'.').', available '.$item['quantity'].'). Please restock or adjust the material recipe.');
            $update=$conn->prepare('UPDATE inventory SET quantity=quantity-? WHERE id=?');
            $update->bind_param('di',$delta,$iid); if (!$update->execute()) throw new RuntimeException($update->error); $update->close();
            $kind=$delta>0?'out':'in'; $amount=abs($delta);
            $note=$delta>0?'Automatic raw-material allocation for order':'Raw material restored after order change/cancellation';
            $txn=$conn->prepare('INSERT INTO inventory_transactions(inventory_id,type,quantity,reference,notes,created_by) VALUES(?,?,?,?,?,?)');
            $actor=$userId>0?$userId:null;
            $txn->bind_param('isdssi',$iid,$kind,$amount,$reference,$note,$actor); if (!$txn->execute()) throw new RuntimeException($txn->error); $txn->close();
            if ($new<=0) {
                if (isset($existing[$iid])) { $rowId=(int)$existing[$iid]['id']; $del=$conn->prepare('DELETE FROM order_items WHERE id=?'); $del->bind_param('i',$rowId); if (!$del->execute()) throw new RuntimeException($del->error); $del->close(); }
            } elseif (isset($existing[$iid])) {
                $rowId=(int)$existing[$iid]['id']; $nm=$target[$iid]['name']; $unit=$target[$iid]['unit'];
                $upd=$conn->prepare('UPDATE order_items SET item_name=?,unit=?,quantity_used=? WHERE id=?');
                $upd->bind_param('ssdi',$nm,$unit,$new,$rowId); if (!$upd->execute()) throw new RuntimeException($upd->error); $upd->close();
            } else {
                $nm=$target[$iid]['name']; $unit=$target[$iid]['unit'];
                $ins=$conn->prepare('INSERT INTO order_items(order_id,inventory_id,item_name,quantity_used,unit,notes) VALUES(?,?,?,?,?,?)');
                $ins->bind_param('iisdss',$orderId,$iid,$nm,$new,$unit,$marker); if (!$ins->execute()) throw new RuntimeException($ins->error); $ins->close();
            }
        }
        return ['ok'=>true];
    } catch (Throwable $e) { return ['ok'=>false,'error'=>$e->getMessage()]; }
}

/** Customer-visible product status; uses the same live material requirements as checkout. */
function printshopProductAvailabilityMap(mysqli $conn, array $products): array {
    ensurePrintshopMaterialSchema($conn);
    $status = [];
    foreach ($products as $product) {
        $id = (int)$product['id'];
        $status[$id] = [
            'available' => true,
            'reason' => '',
            'max_order_qty' => 999,
            'has_material_recipe' => false,
        ];
        if ((float)($product['unit_price'] ?? 0) <= 0) {
            $status[$id]['available'] = false;
            $status[$id]['reason'] = 'Price not set yet. Please contact the shop.';
            $status[$id]['max_order_qty'] = 0;
        }
    }
    if (!$status) return [];

    $ids = implode(',', array_map('intval', array_keys($status)));
    $result = $conn->query("SELECT pm.product_id, pm.qty_per_unit,
        i.id AS material_found, i.name, i.unit, i.quantity, i.status
        FROM product_materials pm LEFT JOIN inventory i ON i.id=pm.inventory_id
        WHERE pm.product_id IN ($ids) ORDER BY pm.product_id, pm.inventory_id");
    if (!$result) throw new RuntimeException('Unable to read material availability.');
    $limiters = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['product_id'];
        $status[$id]['has_material_recipe'] = true;
        $needed = (float)$row['qty_per_unit'];
        $name = (string)($row['name'] ?? 'an inventory item');
        if ($row['material_found'] === null || $row['status'] !== 'active' || $needed <= 0) {
            $limiters[$id][] = ($row['material_found'] === null)
                ? 'A required material is missing from inventory.'
                : ($needed <= 0 ? 'Material recipe needs updating. Please contact the shop.' : $name . ' is not active in inventory.');
            continue;
        }
        $stock = (float)$row['quantity'];
        $possible = (int)floor(($stock + 0.00001) / $needed);
        $status[$id]['max_order_qty'] = min($status[$id]['max_order_qty'], max(0, $possible));
        if ($possible < 1) {
            $fmt = static function (float $n): string { return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); };
            $limiters[$id][] = $name . ' (need ' . $fmt($needed) . ' ' . $row['unit']
                . ', available ' . $fmt($stock) . ' ' . $row['unit'] . ').';
        }
    }
    $result->free();
    foreach ($status as $id => &$item) {
        if (!$item['has_material_recipe']) {
            $item['available'] = false;
            $item['max_order_qty'] = 0;
            if ($item['reason'] === '') $item['reason'] = 'Materials are not configured for this product. Please contact the shop.';
        } elseif (!empty($limiters[$id])) {
            $item['available'] = false;
            $item['max_order_qty'] = 0;
            // Display a useful and specific reason without rendering a long list on each card.
            if ($item['reason'] === '') $item['reason'] = implode(' ', array_slice($limiters[$id], 0, 2))
                . (count($limiters[$id]) > 2 ? ' More materials are unavailable.' : '');
        }
    }
    unset($item);
    return $status;
}
