<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}
$pageTitle = 'Products';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/product_images.php';
require_once __DIR__ . '/../includes/product_sizes.php';
require_once __DIR__ . '/../includes/preorders.php';
ensureCatalogSchema($conn);
ensureProductSizePriceSchema($conn);

if (empty($_SESSION['products_admin_csrf'])) {
    $_SESSION['products_admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['products_admin_csrf'];
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$showArch = ($_GET['archived'] ?? '') === '1';
$msg = (string)($_SESSION['products_admin_notice'] ?? '');
unset($_SESSION['products_admin_notice']);
$err = '';
$failedForm = null;
$submittedSizePrices = null;
$failedSizeRows = null;
$uploadPath = __DIR__ . '/../uploads/product-images/';

/** POST-only actions prevent accidental deletion by links/crawlers and protect session state. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $operation = (string)($_POST['operation'] ?? '');
    $pid = (int)($_POST['product_id'] ?? 0);
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $err = 'Session expired. Refresh this page and try again.';
    } elseif (in_array($operation, ['archive', 'restore'], true)) {
        // Archive rather than delete: preserve product ID, orders and material recipes.
        if ($pid < 1) {
            $err = 'Choose a valid product.';
        } else {
            try {
                $newStatus = $operation === 'restore' ? 'active' : 'archived';
                $stmt = $conn->prepare('UPDATE products SET status=? WHERE id=?');
                if (!$stmt) throw new RuntimeException('Unable to prepare product archive update.');
                $stmt->bind_param('si', $newStatus, $pid);
                if (!$stmt->execute()) throw new RuntimeException('Unable to update product archive.');
                $changed = $stmt->affected_rows;
                $stmt->close();
                $_SESSION['products_admin_notice'] = !$changed ? 'No changes were needed.' :
                    ($operation === 'restore' ? 'Product restored to the customer Shop.' :
                    'Product archived. It is no longer in the customer Shop; previous orders remain saved.');
                header('Location: products.php' . ($showArch ? '?archived=1' : ''));
                exit;
            } catch (Throwable $ex) {
                error_log('Admin products archive: ' . $ex->getMessage());
                $err = 'Could not update the product archive. Please try again.';
            }
        }
    } elseif ($operation === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $unitInput = trim((string)($_POST['unit'] ?? 'piece'));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $orderMode = 'regular';
        $priceInput = (string)($_POST['unit_price'] ?? '');
        $failedForm = $_POST;
        $submittedSizePrices = null;
        $sizesSubmitted = (string)($_POST['sizes_submitted'] ?? '') === '1';
        $submittedSizeRows = is_array($_POST['sizes'] ?? null) ? array_values($_POST['sizes']) : [];
        $unitPrice = is_numeric($priceInput) ? (float)$priceInput : -1;
        if (!in_array($orderMode, ['regular','preorder'], true)) {
            $err = 'Choose a valid order type.';
        } elseif ($name === '' || mb_strlen($name) > 150) {
            $err = 'Enter a product name (up to 150 characters).';
        } elseif (mb_strlen($description) > 15000) {
            $err = 'Product description is too long.';
        } elseif ($unitPrice < 0 || !is_finite($unitPrice) || $unitPrice > 99999999.99) {
            $err = 'Enter a valid non-negative unit price.';
        } elseif ($unitInput === '' || mb_strlen($unitInput) > 30) {
            $err = 'Enter a unit (up to 30 characters).';
        } else {
            $categoryValid = $categoryId > 0 ? $conn->prepare("SELECT id FROM categories WHERE id=? AND status='active' LIMIT 1") : null;
            if ($categoryValid) {
                $categoryValid->bind_param('i', $categoryId);
                $categoryValid->execute();
                $exists = (bool)$categoryValid->get_result()->fetch_assoc();
                $categoryValid->close();
                if (!$exists) $err = 'Choose an active category.';
            }
            if ($categoryId < 1) $err = 'Choose a category to display this product in the Shop.';
        }
        $current = null;
        if (!$err && $pid > 0) {
            $stmt = $conn->prepare('SELECT id,image,status FROM products WHERE id=? LIMIT 1');
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) $err = 'Product not found. Refresh the page.';
        }
        if (!$err) {
            $dup = $conn->prepare('SELECT id FROM products WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND id<>? LIMIT 1');
            $dup->bind_param('si', $name, $pid);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) $err = 'A product with this name already exists (check Archived too).';
            $dup->close();
        }
        // Validate the admin-managed size list: every row needs a unique label and a valid price.
        $validSizeList = [];
        if (!$err && $sizesSubmitted) {
            if (count($submittedSizeRows) > 60) {
                $err = 'A product can have up to 60 sizes.';
            } else {
                $seenLabels = [];
                foreach ($submittedSizeRows as $row) {
                    if (!is_array($row)) continue;
                    $label = trim(preg_replace('/\s+/u', ' ', (string)($row['label'] ?? '')));
                    $rawPrice = trim((string)($row['price'] ?? ''));
                    if ($label === '' && $rawPrice === '') continue; // ignore fully blank rows
                    if ($label === '' || mb_strlen($label) > 190) { $err = 'Each size needs a name (up to 190 characters).'; break; }
                    $key = mb_strtolower($label, 'UTF-8');
                    if (isset($seenLabels[$key])) { $err = 'Size "' . $label . '" is listed twice. Each size name must be different.'; break; }
                    $seenLabels[$key] = true;
                    if (!preg_match('/\A(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?\z/D', $rawPrice)) { $err = 'Enter a valid price (up to 2 decimal places) for ' . $label . '.'; break; }
                    $price = (float)$rawPrice;
                    if ($price <= 0 || $price > 99999999.99 || !is_finite($price)) { $err = 'The price for ' . $label . ' must be greater than zero.'; break; }
                    $validSizeList[] = ['size' => $label, 'price' => round($price, 2)];
                }
            }
            $failedSizeRows = $validSizeList ?: array_map(static fn($r) => ['size' => (string)($r['label'] ?? ''), 'price' => (float)($r['price'] ?? 0)], array_filter($submittedSizeRows, 'is_array'));
        }
        $imageName = (string)($current['image'] ?? '');
        $newImagePath = '';
        // Accept only decoded raster images; never store arbitrary uploaded files or SVG/HTML.
        if (!$err && isset($_FILES['product_image']) && (int)($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['product_image'];
            if ((int)$file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] < 1 || (int)$file['size'] > 5 * 1024 * 1024 || !is_uploaded_file((string)$file['tmp_name'])) {
                $err = 'Please upload a valid image smaller than 5 MB.';
            } else {
                $info = @getimagesize((string)$file['tmp_name']);
                $mime = $info['mime'] ?? '';
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime]) || empty($info[0]) || empty($info[1])) {
                    $err = 'Only JPG, PNG or WebP photos are supported.';
                } else {
                    $imageName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    if (!is_dir($uploadPath) && !mkdir($uploadPath, 0755, true) && !is_dir($uploadPath)) {
                        $err = 'Could not create the image folder. Check server permissions.';
                    } elseif (!move_uploaded_file((string)$file['tmp_name'], $uploadPath . $imageName)) {
                        $err = 'Could not save the product photo. Check server permissions.';
                    } else {
                        $newImagePath = $uploadPath . $imageName;
                    }
                }
            }
        }
        if (!$err) {
            try {
                // Product and its new size prices must save together or not at all.
                $conn->begin_transaction();
                $unitPrice = round($unitPrice, 2);
                $category = $categoryId;
                $unit = $unitInput;
                if ($pid > 0) {
                    // Editing a product never alters its archive status or priority.
                    $stmt = $conn->prepare('UPDATE products SET category_id=?,name=?,description=?,image=?,unit_price=?,unit=?,order_mode=? WHERE id=?');
                    if (!$stmt) throw new RuntimeException('Could not prepare product update.');
                    $stmt->bind_param('isssdssi', $category, $name, $description, $imageName, $unitPrice, $unit, $orderMode, $pid);
                    if (!$stmt->execute()) throw new RuntimeException('Could not update product.');
                    $stmt->close();
                    $message = 'Product updated.';
                } else {
                    // Database defaults supply normal priority and active status.
                    $stmt = $conn->prepare('INSERT INTO products (category_id,name,description,image,unit_price,unit,order_mode) VALUES (?,?,?,?,?,?,?)');
                    if (!$stmt) throw new RuntimeException('Could not prepare new product.');
                    $stmt->bind_param('isssdss', $category, $name, $description, $imageName, $unitPrice, $unit, $orderMode);
                    if (!$stmt->execute()) throw new RuntimeException('Could not add product.');
                    $pid = (int)$conn->insert_id;
                    $stmt->close();
                    $message = 'Product added.';
                }
                if ($sizesSubmitted) {
                    ensureProductSizePriceSchema($conn);
                    // Only freeze the list when the admin actually changed it, so untouched products keep following the built-in defaults.
                    $currentOptions = printshopSizeOptions($name, $unitPrice, $conn, $pid);
                    if ($currentOptions !== $validSizeList) {
                        $del = $conn->prepare('DELETE FROM product_size_list WHERE product_id=?');
                        if (!$del) throw new RuntimeException('Could not prepare size list reset.');
                        $del->bind_param('i', $pid);
                        if (!$del->execute()) throw new RuntimeException('Could not reset size list.');
                        $del->close();
                        $ins = $conn->prepare('INSERT INTO product_size_list (product_id,size_label,price,sort_order) VALUES (?,?,?,?)');
                        if (!$ins) throw new RuntimeException('Could not prepare size list save.');
                        foreach ($validSizeList as $i => $opt) {
                            $label = $opt['size']; $price = $opt['price']; $order = $i + 1;
                            $ins->bind_param('isdi', $pid, $label, $price, $order);
                            if (!$ins->execute()) throw new RuntimeException('Could not save sizes.');
                        }
                        $ins->close();
                        $cfg = $conn->prepare('INSERT INTO product_size_config (product_id,customized) VALUES (?,1) ON DUPLICATE KEY UPDATE customized=1');
                        if (!$cfg) throw new RuntimeException('Could not prepare size settings.');
                        $cfg->bind_param('i', $pid);
                        if (!$cfg->execute()) throw new RuntimeException('Could not save size settings.');
                        $cfg->close();
                        $message .= ' Sizes updated.';
                    }
                }
                $conn->commit();
                $message .= $pid > 0 && ($current['status'] ?? 'active') === 'archived'
                    ? ' Product remains archived until you restore it.'
                    : ' Listed in the customer Shop (subject to price and material availability).';
                $_SESSION['products_admin_notice'] = $message;
                header('Location: products.php' . ($pid > 0 && ($current['status'] ?? 'active') === 'archived' ? '?archived=1' : ''));
                exit;
            } catch (Throwable $ex) {
                $conn->rollback();
                if ($newImagePath !== '' && is_file($newImagePath)) @unlink($newImagePath);
                error_log('Admin save product: ' . $ex->getMessage());
                $err = 'Could not save the product. Please check the database and try again.';
            }
        }
    } else {
        $err = 'Unknown product action.';
    }
}

$products = [];
$stmt = $conn->prepare('SELECT p.*,c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status=? ORDER BY p.sort_order,p.name,p.id');
$statusFilter = $showArch ? 'archived' : 'active';
$stmt->bind_param('s', $statusFilter);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$categories = getActiveCategoriesList($conn);
$editProd = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM products WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editProd = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
if ($failedForm !== null) $editProd = $failedForm;
$openForm = $editProd !== null || ($_GET['add'] ?? '') === '1';
$editImage = $editProd ? productDisplayImage($editProd) : null;
$editPriceOptions = [];
if ($failedSizeRows !== null) {
    $editPriceOptions = $failedSizeRows;
} elseif ($editProd && (int)($editProd['product_id'] ?? $editProd['id'] ?? 0) > 0) {
    $editPriceOptions = printshopSizeOptions(
        (string)($editProd['name'] ?? ''), (float)($editProd['unit_price'] ?? 0),
        $conn, (int)($editProd['product_id'] ?? $editProd['id'])
    );
}

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/admin-products-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-products-ui.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<main class="ap-catalog" id="adminProductsCatalog">
  <?php if ($msg): ?><div class="alert alert-success" role="status"><?= $e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error" role="alert"><?= $e($err) ?></div><?php endif; ?>
  <section class="ap-hero" aria-labelledby="apTitle">
    <div class="ap-hero-copy">
      <div class="ap-eyebrow"><span class="ap-eyebrow-dot"></span> BRILLÉ AMOUR · CATALOG CONTROL</div>
      <h1 id="apTitle">Your products, all in one place.</h1>
      <p>Add and edit your printing-shop products. Archive products you no longer sell.</p>
      <div class="ap-hero-meta"><span><?= count($products) ?> <?= $showArch ? 'archived' : 'active' ?> product<?= count($products) === 1 ? '' : 's' ?></span><span class="ap-hero-separator" aria-hidden="true"></span><span>Customer Shop management</span></div>
    </div>
    <div class="ap-hero-action"><a class="ap-btn ap-btn-primary" href="products.php?add=1"><span aria-hidden="true" class="ap-plus">+</span> Add product</a></div>
  </section>

  <div class="ap-tabs" role="navigation" aria-label="Product archive">
    <a href="products.php" class="ap-tab<?= $showArch ? '' : ' is-current' ?>" <?= !$showArch ? 'aria-current="page"' : '' ?>>Active products</a>
    <a href="products.php?archived=1" class="ap-tab<?= $showArch ? ' is-current' : '' ?>" <?= $showArch ? 'aria-current="page"' : '' ?>>Archived products</a>
  </div>
  <section class="ap-tools" aria-label="Find products">
    <label class="ap-search" for="apSearch"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.5"/><path d="m16 16 4.5 4.5"/></svg><input id="apSearch" type="search" placeholder="Search products..." autocomplete="off"></label>
    <label class="ap-category-filter" for="apCategory"><span>Category</span><select id="apCategory"><option value="">All categories</option><?php foreach ($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= $e($cat['name']) ?></option><?php endforeach; ?></select></label>
    <span class="ap-count" id="apResultCount" role="status" aria-live="polite"><?= count($products) ?> products</span>
  </section>

  <section class="ap-products-grid" id="apProductsGrid" aria-label="<?= $showArch ? 'Archived' : 'Active' ?> products">
    <?php foreach ($products as $product):
      $thumb = productDisplayImage($product);
      $pid = (int)$product['id'];
      $catId = (int)($product['category_id'] ?? 0);
      $pName = (string)($product['name'] ?? 'Product');
      $pDesc = trim((string)($product['description'] ?? ''));
      $cardSizeOptions = printshopSizeOptions($pName, (float)$product['unit_price'], $conn, $pid);
    ?>
    <article class="ap-product" data-product-search="<?= $e(mb_strtolower($pName . ' ' . $pDesc . ' ' . ($product['cat_name'] ?? ''))) ?>" data-product-category="<?= $catId ?>">
      <div class="ap-product-photo">
        <?php if ($thumb): ?><img src="../<?= $e($thumb) ?>" alt="<?= $e($pName) ?>" loading="lazy">
        <?php else: ?><span class="ap-photo-empty" aria-hidden="true">BA</span><?php endif; ?>
        <?php if ($showArch): ?><span class="ap-product-badge is-archived"><span class="ap-badge-dot"></span>Archived</span><?php endif; ?>
      </div>
      <div class="ap-product-info">
        <div class="ap-product-category"><?= $e($product['cat_name'] ?? 'Uncategorized') ?></div>
        <h2 title="<?= $e($pName) ?>"><?= $e($pName) ?></h2>
        <p class="ap-product-description"><?= $e($pDesc !== '' ? $pDesc : 'Add a description in Edit Product.') ?></p>
        <div class="ap-product-price"><div><small><?= $cardSizeOptions ? 'From · ' . count($cardSizeOptions) . ' options' : 'Base price' ?></small><strong><?= formatMoney($cardSizeOptions ? min(array_column($cardSizeOptions, 'price')) : (float)$product['unit_price']) ?></strong></div><span><?= $cardSizeOptions ? 'per selected size / package' : 'per ' . $e($product['unit'] ?? 'piece') ?></span></div>
      </div>
      <div class="ap-card-actions">
        <a class="ap-card-edit" href="products.php?edit=<?= $pid ?><?= $showArch ? '&archived=1' : '' ?>">Edit product</a>
        <?php if ($showArch): ?>
        <form method="post" action="products.php?archived=1"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="product_id" value="<?= $pid ?>"><input type="hidden" name="operation" value="restore"><button class="ap-card-toggle" type="submit">Restore</button></form>
        <?php else: ?>
        <form method="post" action="products.php" onsubmit="return confirm('Archive this product? It will leave the customer Shop, but past orders and material recipes will be preserved.');"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="product_id" value="<?= $pid ?>"><input type="hidden" name="operation" value="archive"><button type="submit" class="ap-card-archive" aria-label="Archive <?= $e($pName) ?>">Archive</button></form>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <section class="ap-empty" <?= count($products) ? 'hidden' : '' ?> id="apNoProducts"><strong>No <?= $showArch ? 'archived' : 'active' ?> products yet.</strong><p><?= $showArch ? 'Archived products appear here. You can restore them when you are ready to sell them again.' : 'Start by adding a product to your customer Shop.' ?></p><?php if (!$showArch): ?><a class="ap-btn ap-btn-primary" href="products.php?add=1">+ Add your first product</a><?php endif; ?></section>
  <section class="ap-empty" hidden id="apNoSearch"><strong>No matching products.</strong><p>Try another search or choose All categories.</p></section>
  <p class="ap-inventory-note"><strong>Before selling a new product:</strong> Set its material recipe and stock in <a href="inventory.php#materialRecipes">Inventory → Product material recipes</a>. Archived products do not appear in the Customer Shop, and previous orders remain saved.</p>
</main>

<div class="modal-backdrop <?= $openForm ? 'open' : '' ?>" id="prodModal">
  <div class="modal ap-editor" role="dialog" aria-modal="true" aria-labelledby="apEditorTitle">
    <div class="modal-header ap-editor-head">
      <div><span class="ap-form-eyebrow">PRODUCT MANAGEMENT</span><h2 class="modal-title" id="apEditorTitle"><?= $editProd && !empty($editProd['product_id'] ?? $editProd['id'] ?? 0) ? 'Edit product' : 'Add a new product' ?></h2><p>Complete the details customers will see in your Shop.</p></div>
      <a href="products.php<?= $showArch ? '?archived=1' : '' ?>" class="ap-editor-close" aria-label="Close product form">×</a>
    </div>
    <form class="products-admin-form" method="post" action="products.php<?= $showArch ? '?archived=1' : '' ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="operation" value="save"><input type="hidden" name="product_id" value="<?= (int)($editProd['product_id'] ?? $editProd['id'] ?? 0) ?>">
      <div class="modal-body ap-editor-body">
        <div class="ap-form-section"><span class="ap-form-section-index">01</span><div><h3>Product information</h3><p>Name, category, and a short description</p></div></div>
        <div class="form-group"><label for="productName">Product name <span class="ap-required">*</span></label><input id="productName" name="name" maxlength="150" required placeholder="e.g. Acrylic keychain" value="<?= $e($editProd['name'] ?? '') ?>"></div>
        <div class="form-group"><label for="productCategory">Category <span class="ap-required">*</span></label><select id="productCategory" name="category_id" required><option value="">Choose category</option><?php foreach ($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= (int)($editProd['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= $e($cat['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="productDesc">Description <span class="ap-optional">Optional</span></label><textarea id="productDesc" name="description" rows="2" maxlength="15000" placeholder="Describe this product in a sentence."><?= $e($editProd['description'] ?? '') ?></textarea></div>
        <div class="ap-form-section"><span class="ap-form-section-index">02</span><div><h3>Product image</h3><p>Use a clear picture for the customer Shop</p></div></div>
        <div class="ap-photo-picker"><div class="ap-image-preview" id="apImagePreview"><?php if ($editImage): ?><img src="../<?= $e($editImage) ?>" alt="Current product photo" id="apPreviewImg"><?php else: ?><img src="" alt="Selected product photo" id="apPreviewImg" hidden><span id="apPreviewPlaceholder">No photo selected</span><?php endif; ?></div><div class="ap-photo-fields"><label for="productPhoto">Upload product photo <span class="ap-optional">Optional</span></label><input id="productPhoto" type="file" name="product_image" accept="image/jpeg,image/png,image/webp" aria-describedby="apPhotoHelp"><small id="apPhotoHelp">JPG, PNG or WebP · max 5 MB. Leave empty to keep the current image.</small></div></div>
        <div class="ap-form-section"><span class="ap-form-section-index">03</span><div><h3>Price &amp; unit</h3><p>Set the starting price and selling unit</p></div></div>
        <div class="ap-form-columns"><div class="form-group"><label for="productPrice">Base price (₱) <span class="ap-required">*</span></label><input id="productPrice" type="number" name="unit_price" min="0" max="99999999.99" step="0.01" required value="<?= $e($editProd['unit_price'] ?? 0) ?>"></div><div class="form-group"><label for="productUnit">Unit <span class="ap-required">*</span></label><input id="productUnit" name="unit" maxlength="30" required value="<?= $e($editProd['unit'] ?? 'piece') ?>"></div></div>
        <div class="ap-form-section ap-size-section"><span class="ap-form-section-index">04</span><div><h3>Sizes / packages</h3><p>Add, rename, re-price or delete the options customers can choose.</p></div></div>
        <input type="hidden" name="sizes_submitted" value="1">
        <div class="ap-size-prices" id="apSizeRows">
          <?php foreach ($editPriceOptions as $i => $option): ?>
            <div class="ap-size-price-row ap-size-edit-row">
              <input type="text" class="ap-size-name" name="sizes[<?= (int)$i ?>][label]" maxlength="190" placeholder="Size name (e.g. A4)" value="<?= $e($option['size']) ?>" aria-label="Size name">
              <span class="ap-size-price-field"><b>₱</b><input type="number" name="sizes[<?= (int)$i ?>][price]" min="0.01" max="99999999.99" step="0.01" placeholder="Price" value="<?= $e(number_format((float)$option['price'], 2, '.', '')) ?>" aria-label="Price"></span>
              <button type="button" class="ap-size-remove" data-remove-size aria-label="Delete this size" title="Delete this size">&times;</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="ap-btn ap-btn-secondary ap-size-add" id="apAddSize">+ Add size</button>
        <p class="ap-form-help" id="apNoSizesHelp" <?= $editPriceOptions ? 'hidden' : '' ?>>No sizes yet. Customers will be charged the base price. Click "Add size" to offer size choices.</p>
        <p class="ap-form-help">Changes apply to the customer Shop, cart and checkout after you save. Existing orders keep their original size and price. Set up required materials in <a href="inventory.php#materialRecipes">Inventory</a>.</p>
      </div>
      <div class="modal-footer ap-editor-footer"><a class="ap-btn ap-btn-secondary" href="products.php<?= $showArch ? '?archived=1' : '' ?>">Cancel</a><button type="submit" class="ap-btn ap-btn-primary">Save product</button></div>
    </form>
  </div>
</div>
<script>
(function () {
  var query = document.getElementById('apSearch');
  var category = document.getElementById('apCategory');
  var cards = Array.from(document.querySelectorAll('#apProductsGrid .ap-product'));
  var results = document.getElementById('apResultCount');
  var noMatches = document.getElementById('apNoSearch');
  function filterProducts() {
    var needle = query.value.trim().toLocaleLowerCase();
    var chosen = category.value;
    var visible = 0;
    cards.forEach(function (card) {
      var matches = (!needle || card.dataset.productSearch.includes(needle)) && (!chosen || card.dataset.productCategory === chosen);
      card.hidden = !matches;
      if (matches) visible++;
    });
    results.textContent = visible + ' product' + (visible === 1 ? '' : 's');
    noMatches.hidden = visible !== 0 || cards.length === 0;
  }
  if (query && category) { query.addEventListener('input', filterProducts); category.addEventListener('change', filterProducts); }
  var rows = document.getElementById('apSizeRows');
  var addBtn = document.getElementById('apAddSize');
  var noSizes = document.getElementById('apNoSizesHelp');
  var nextIndex = rows ? rows.children.length + 1000 : 0;
  function syncSizeHelp() { if (noSizes && rows) noSizes.hidden = rows.children.length > 0; }
  if (addBtn && rows) {
    addBtn.addEventListener('click', function () {
      var i = nextIndex++;
      var row = document.createElement('div');
      row.className = 'ap-size-price-row ap-size-edit-row';
      row.innerHTML = '<input type="text" class="ap-size-name" name="sizes[' + i + '][label]" maxlength="190" placeholder="Size name (e.g. A4)" aria-label="Size name">' +
        '<span class="ap-size-price-field"><b>\u20b1</b><input type="number" name="sizes[' + i + '][price]" min="0.01" max="99999999.99" step="0.01" placeholder="Price" aria-label="Price"></span>' +
        '<button type="button" class="ap-size-remove" data-remove-size aria-label="Delete this size" title="Delete this size">&times;</button>';
      rows.appendChild(row);
      syncSizeHelp();
      row.querySelector('.ap-size-name').focus();
    });
    rows.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-remove-size]');
      if (!btn) return;
      btn.closest('.ap-size-edit-row').remove();
      syncSizeHelp();
    });
  }
  var picker = document.getElementById('productPhoto');
  var preview = document.getElementById('apPreviewImg');
  var placeholder = document.getElementById('apPreviewPlaceholder');
  var previewUrl = null;
  if (picker && preview) picker.addEventListener('change', function () {
    if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; }
    var file = picker.files && picker.files[0];
    if (file && /^image\/(jpeg|png|webp)$/.test(file.type)) {
      previewUrl = URL.createObjectURL(file);
      preview.src = previewUrl;
      preview.hidden = false;
      if (placeholder) placeholder.hidden = true;
    }
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
