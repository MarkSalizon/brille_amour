<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/product_sizes.php';
require_once '../includes/product_images.php';
require_once '../includes/payments.php';
require_once '../includes/paymongo.php';
require_once '../includes/pickup_capacity.php';
require_once '../includes/innovation.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: ../index.php?auth=login');
    exit;
}
ensureCatalogSchema($conn);
ensureCheckoutPaymentSchema($conn);
ensurePaymongoSchema($conn);
ensurePickupCapacitySchema($conn);
ensureInnovationSchema($conn);

if (empty($_SESSION['customer_order_csrf'])) {
    $_SESSION['customer_order_csrf'] = bin2hex(random_bytes(32));
}
$checkoutCsrf = (string)$_SESSION['customer_order_csrf'];
$products = getActiveProductsList($conn);
$productAvailability = printshopProductAvailabilityMap($conn, $products);
$categories = getActiveCategoriesList($conn);
$portalActive = 'home';
$paymongoAvailable = paymongoReady();
$profileContact = trim((string)($customer['contact'] ?? ''));
$profileAddress = trim((string)($customer['address'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Home - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/product-images.css?v=<?= filemtime(__DIR__ . '/../assets/css/product-images.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-shop-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-shop-clean.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-checkout-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-checkout-ui.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-checkout-extra.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-checkout-extra.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/customer-checkout-address.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-checkout-address.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-product-card-size.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-product-card-size.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-size-options.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-size-options.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-preorder-card-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-preorder-card-fix.css') ?>">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
  <link rel="stylesheet" href="../assets/css/checkout-mobile-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/checkout-mobile-fix.css') ?>">
  <style id="shop-card-equal-height-fix">
    /* Final storefront alignment fix: kept inside shop.php so InfinityFree/browser CSS cache cannot hide it. */
    .customer-shop-main #shopProductGrid {
      align-items: stretch !important;
      grid-auto-rows: 1fr !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card {
      display: flex !important;
      flex-direction: column !important;
      align-self: stretch !important;
      height: 100% !important;
      min-height: 440px !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .portal-shop-card-media {
      flex: 0 0 165px !important;
      height: 165px !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .portal-shop-card-body {
      display: flex !important;
      flex: 1 1 auto !important;
      flex-direction: column !important;
      height: auto !important;
      min-height: 0 !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .product-category {
      min-height: 17px !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card h2 {
      min-height: 44px !important;
      max-height: 44px !important;
      overflow: hidden !important;
      display: -webkit-box !important;
      -webkit-box-orient: vertical !important;
      -webkit-line-clamp: 2 !important;
      line-clamp: 2 !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .shop-product-caption {
      min-height: 21px !important;
      max-height: 21px !important;
      overflow: hidden !important;
      white-space: nowrap !important;
      text-overflow: ellipsis !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .shop-size-picker {
      box-sizing: border-box !important;
      display: grid !important;
      gap: 6px !important;
      width: 100% !important;
      min-width: 0 !important;
      min-height: 62px !important;
      margin: 0 !important;
      align-content: end !important;
      color: #6f4658 !important;
      font-family: "Outfit", sans-serif !important;
      font-size: 12px !important;
      font-weight: 700 !important;
      line-height: 1.2 !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .shop-size-picker select {
      -webkit-appearance: none !important;
      appearance: none !important;
      box-sizing: border-box !important;
      display: block !important;
      width: 100% !important;
      min-width: 0 !important;
      height: 40px !important;
      margin: 0 !important;
      padding: 0 38px 0 12px !important;
      border: 1px solid #ead7df !important;
      border-radius: 10px !important;
      outline: none !important;
      background-color: #ffffff !important;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236f4658' stroke-width='2.25' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
      background-repeat: no-repeat !important;
      background-position: right 12px center !important;
      background-size: 15px 15px !important;
      color: #35272f !important;
      font-family: "Outfit", sans-serif !important;
      font-size: 13px !important;
      font-weight: 600 !important;
      line-height: 40px !important;
      box-shadow: 0 1px 2px rgba(74, 40, 55, .03) !important;
      cursor: pointer !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .shop-size-picker select:hover {
      border-color: #dba9ba !important;
      background-color: #fffafb !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .shop-size-picker select:focus {
      border-color: #c83b65 !important;
      box-shadow: 0 0 0 3px rgba(200, 59, 101, .10) !important;
      background-color: #ffffff !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .portal-product-meta {
      margin-top: auto !important;
      min-height: 24px !important;
    }
    .customer-shop-main #shopProductGrid > .portal-shop-card .portal-buy-actions {
      min-height: 44px !important;
      margin-top: 9px !important;
    }
    @media (max-width: 760px) {
      .customer-shop-main #shopProductGrid > .portal-shop-card {
        min-height: 425px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-shop-card-media {
        flex-basis: 155px !important;
        height: 155px !important;
      }
    }
    @media (max-width: 640px) {
      /* Two products per row on mobile, with protected text areas so nothing overlaps. */
      .customer-shop-main #shopProductGrid {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px !important;
        align-items: stretch !important;
        grid-auto-rows: 1fr !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card {
        width: auto !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 390px !important;
        padding: 8px !important;
        border-radius: 14px !important;
        overflow: hidden !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-shop-card-media {
        flex: 0 0 130px !important;
        width: 100% !important;
        height: 130px !important;
        min-height: 130px !important;
        border-radius: 10px !important;
        overflow: hidden !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-shop-card-body {
        gap: 5px !important;
        padding: 9px 1px 1px !important;
        overflow: hidden !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .product-category {
        min-height: 14px !important;
        max-height: 14px !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        text-overflow: ellipsis !important;
        font-size: 9.5px !important;
        line-height: 14px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card h2 {
        min-height: 34px !important;
        max-height: 34px !important;
        margin: 0 !important;
        font-size: 13px !important;
        line-height: 17px !important;
        overflow: hidden !important;
        overflow-wrap: anywhere !important;
        word-break: normal !important;
        display: -webkit-box !important;
        -webkit-box-orient: vertical !important;
        -webkit-line-clamp: 2 !important;
        line-clamp: 2 !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .shop-product-caption {
        min-height: 30px !important;
        max-height: 30px !important;
        margin: 0 !important;
        font-size: 10.5px !important;
        line-height: 15px !important;
        white-space: normal !important;
        overflow: hidden !important;
        overflow-wrap: anywhere !important;
        display: -webkit-box !important;
        -webkit-box-orient: vertical !important;
        -webkit-line-clamp: 2 !important;
        line-clamp: 2 !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .shop-size-picker {
        min-height: 52px !important;
        gap: 4px !important;
        font-size: 10px !important;
        line-height: 1.15 !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .shop-size-picker select {
        height: 34px !important;
        padding: 0 27px 0 8px !important;
        background-position: right 8px center !important;
        background-size: 12px 12px !important;
        font-size: 11px !important;
        line-height: 34px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-product-meta {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) !important;
        align-content: end !important;
        gap: 2px !important;
        min-height: 38px !important;
        margin-top: auto !important;
        padding-top: 3px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-product-meta strong,
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-product-meta .portal-stock {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        text-overflow: ellipsis !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-product-meta strong {
        font-size: 12px !important;
        line-height: 16px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-product-meta .portal-stock {
        font-size: 9.5px !important;
        line-height: 13px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-buy-actions {
        display: grid !important;
        grid-template-columns: 36px minmax(0, 1fr) !important;
        gap: 6px !important;
        width: 100% !important;
        min-height: 38px !important;
        margin-top: 6px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-cart-icon-button {
        width: 36px !important;
        min-width: 36px !important;
        height: 38px !important;
        min-height: 38px !important;
        padding: 0 !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-cart-icon-button svg {
        width: 18px !important;
        height: 18px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-buy-button {
        min-width: 0 !important;
        min-height: 38px !important;
        height: 38px !important;
        padding: 6px 7px !important;
        font-size: 11px !important;
        line-height: 1.1 !important;
        white-space: normal !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-product-unavailable {
        margin-top: 5px !important;
        padding: 5px 6px !important;
        font-size: 9.5px !important;
        line-height: 1.25 !important;
        overflow-wrap: anywhere !important;
      }
    }
    @media (max-width: 380px) {
      .customer-shop-main #shopProductGrid { gap: 8px !important; }
      .customer-shop-main #shopProductGrid > .portal-shop-card {
        min-height: 365px !important;
        padding: 7px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .portal-shop-card-media {
        flex-basis: 112px !important;
        height: 112px !important;
        min-height: 112px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card h2 {
        font-size: 12px !important;
        line-height: 16px !important;
        min-height: 32px !important;
        max-height: 32px !important;
      }
      .customer-shop-main #shopProductGrid > .portal-shop-card .shop-product-caption {
        font-size: 10px !important;
      }
    }
  </style>
</head>
<body class="customer-portal-body" data-commerce-page="shop">
  <?php require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-portal-main customer-shop-main">
    <section class="portal-page-heading customer-shop-heading">
      <h1>Explore our products</h1>
    </section>

    <section class="portal-shop-toolbar" aria-label="Shop filters">
      <label class="portal-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 4 4"></path></svg>
        <input type="search" id="shopSearch" placeholder="Search products" autocomplete="off">
      </label>
      <div class="portal-filter-tabs" id="shopCategoryFilters">
        <button class="active" type="button" data-shop-category="all">All</button>
        <?php foreach ($categories as $category): ?><button type="button" data-shop-category="<?= (int)$category['id'] ?>"><?= htmlspecialchars((string)$category['name']) ?></button><?php endforeach; ?>
      </div>
    </section>

    <div class="customer-shop-results"><span id="shopResultCount" aria-live="polite"><?= count($products) ?> products</span></div>
    <section class="portal-shop-grid" id="shopProductGrid">
      <?php
        // Short storefront captions only. Keep full database descriptions for search and admin details.
        $shopShortDescriptions = [
          'tarpaulin printing' => 'Custom banners for any occasion.',
          'sintra board' => 'Durable printed display boards.',
          'posters' => 'Vibrant posters in your chosen size.',
          'customized shirts' => 'Personalized shirts for any event.',
          'customized mugs' => 'Mugs printed with your design.',
          'acrylic keychains' => 'Personalized acrylic keepsakes.',
          'button pins' => 'Custom pins for events and gifts.',
          'ref magnets' => 'Custom magnets for keepsakes.',
          'bookmarks' => 'Personalized printed bookmarks.',
          'business cards' => 'Professional cards for your brand.',
          'flyers & brochures' => 'Flyers and brochures by size or bundle.',
          'flyers/brochure' => 'Flyers and brochures by size or bundle.',
          'stickers & labels' => 'Printed stickers and labels by size.',
          'stickers/labels' => 'Printed stickers and labels by size.',
          'invitations' => 'Invitations made for your event.',
          'photo printing' => 'Print your favorite memories.',
          'id picture printing' => '14 ID photos in one ₱45 package.',
          'document printing' => 'Clear prints for your documents.',
          'lamination' => 'Protect documents and photos.',
          'pvc id cards' => 'Custom durable ID cards.',
        ];
        foreach ($products as $product):
      ?>
        <?php
          $id = (int)$product['id'];
          $imageIndex = productSampleImageIndex((string)$product['name']);
          $previewImage = productDisplayImage($product);
          $isPreorder = false;
          $hasPrice = (float)$product['unit_price'] > 0;
          $sizeOptions = printshopSizeOptions((string)$product['name'], (float)$product['unit_price'], $conn, (int)$product['id']);
          $inventoryId = (int)($product['inventory_id'] ?? 0);
          $quantity = $product['inventory_quantity'] === null ? null : (float)$product['inventory_quantity'];
          $maxQty = $inventoryId > 0 && $quantity !== null ? max(1, (int)floor($quantity)) : 999;
          $stockLabel = 'Made to order'; // Customer-facing product type; inventory safeguards stay in place.
          $availability = $productAvailability[$id] ?? ['available' => false, 'reason' => 'Availability could not be confirmed.', 'max_order_qty' => 0];
          $canBuy = (bool)$availability['available'];
          $unavailableReason = (string)$availability['reason'];
          $maxQty = $canBuy ? (int)$availability['max_order_qty'] : 0;
          $searchText = implode(' ', [(string)$product['name'], (string)($product['category_name'] ?? ''), (string)($product['description'] ?? ''), (string)$product['unit_price']]);
          $fullDescription = trim((string)($product['description'] ?? ''));
          $productCaption = $shopShortDescriptions[mb_strtolower(trim((string)$product['name']), 'UTF-8')]
              ?? ($fullDescription !== '' ? mb_strimwidth($fullDescription, 0, 48, '…', 'UTF-8') : 'Made to order.');
        ?>
        <article class="portal-shop-card" data-shop-product="<?= $id ?>" data-category-id="<?= (int)($product['category_id'] ?? 0) ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
          <div class="portal-shop-card-media">
            <?php if ($previewImage !== null): ?>
              <img class="catalog-product-preview" src="../<?= htmlspecialchars($previewImage, ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string)$product['name']) ?> product sample" loading="lazy">
            <?php elseif ($imageIndex !== null): ?>
              <div class="product-sample-image" role="img" aria-label="<?= htmlspecialchars((string)$product['name']) ?> sample design" style="background-position:<?= ($imageIndex % 5) * 25 ?>% <?= floor($imageIndex / 5) * 100 / 3 ?>%"></div>
            <?php else: ?>
              <div class="portal-product-fallback" aria-hidden="true"><?= htmlspecialchars(strtoupper(mb_substr((string)$product['name'], 0, 1))) ?></div>
            <?php endif; ?>
          </div>
          <div class="portal-shop-card-body">
            <span class="product-category"><?= htmlspecialchars((string)($product['category_name'] ?? 'Custom Product')) ?></span>
            <h2><?= htmlspecialchars((string)$product['name']) ?></h2>
            <p class="shop-product-caption" title="<?= htmlspecialchars($fullDescription !== '' ? $fullDescription : $productCaption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($productCaption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php if ($sizeOptions && !$isPreorder): ?>
              <label class="shop-size-picker">Size / package
                <select data-shop-size="<?= $id ?>" aria-label="Choose size or package for <?= htmlspecialchars((string)$product['name'], ENT_QUOTES) ?>">
                  <?php foreach ($sizeOptions as $sizeOption): ?>
                    <option value="<?= htmlspecialchars($sizeOption['size'], ENT_QUOTES) ?>"><?= htmlspecialchars($sizeOption['size']) ?> · <?= formatMoney($sizeOption['price']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php else: ?>
              <label class="shop-size-picker" aria-hidden="true" style="visibility:hidden;pointer-events:none;">Size / package
                <select tabindex="-1" disabled aria-hidden="true">
                  <option>&nbsp;</option>
                </select>
              </label>
            <?php endif; ?>
            <div class="portal-product-meta"><strong data-shop-price><?= $isPreorder ? 'Price on request' : ($hasPrice ? formatMoney($sizeOptions ? (float)$sizeOptions[0]['price'] : (float)$product['unit_price']) : 'Price on request') ?></strong><span class="portal-stock"><?= htmlspecialchars($stockLabel) ?></span></div>
            <?php if ($isPreorder): ?>
              <div class="portal-buy-actions portal-preorder-actions">
                <a class="portal-btn portal-buy-button portal-preorder-button" href="preorders.php?product=<?= $id ?>">Request quotation</a>
              </div>
            <?php else: ?>
            <div class="portal-buy-actions">
              <button class="portal-cart-icon-button" type="button" data-cart-checkout="<?= $id ?>" data-max="<?= $maxQty ?>" aria-label="Add <?= htmlspecialchars((string)$product['name']) ?> to cart" title="Add to cart">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.1 10.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L20 8H7"></path><circle cx="10" cy="20" r="1.3"></circle><circle cx="17" cy="20" r="1.3"></circle></svg>
              </button>
              <button class="portal-btn portal-buy-button" type="button" data-buy-now="<?= $id ?>" data-max="<?= $maxQty ?>" <?= $canBuy ? '' : 'disabled aria-disabled="true"' ?>><?= $canBuy ? 'Order' : 'Unavailable' ?></button>
            </div>
            <?php endif; ?>
            <?php if (!$isPreorder): ?><p class="portal-product-unavailable" data-product-unavailable role="status" <?= $canBuy ? 'hidden' : '' ?>><?= htmlspecialchars($unavailableReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
    <section class="portal-empty-card" id="shopEmpty" hidden><strong>No products found.</strong><p>Try another keyword or category.</p></section>
  </main>

  <dialog class="portal-checkout-modal" id="portalCheckoutModal" closedby="none" aria-labelledby="portalCheckoutTitle">
    <form class="portal-checkout-modal-card" id="portalCheckoutForm" enctype="multipart/form-data">
      <div class="portal-modal-head">
        <div><span class="portal-eyebrow">BRILLÉ AMOUR · CHECKOUT</span><h2 id="portalCheckoutTitle">Complete your order</h2><p class="checkout-head-caption">Review your items, choose a schedule, and select how to pay.</p></div>
        <button type="button" class="portal-modal-close" id="portalCheckoutClose" aria-label="Close checkout">×</button>
      </div>

      <div class="portal-account-summary">
        <div class="portal-account-summary-icon" aria-hidden="true">✓</div>
        <div><strong><?= htmlspecialchars((string)$customer['name']) ?></strong><span id="portalCheckoutAccountMeta"><?= htmlspecialchars((string)($customer['email'] ?? '')) ?><?= $profileContact !== '' ? ' · ' . htmlspecialchars($profileContact) : ' · No contact number' ?></span></div>
        <button type="button" class="portal-edit-address-link" id="portalCheckoutEditAddress">Edit details</button>
      </div>

      <div class="portal-checkout-alert" id="portalCheckoutContactAlert"<?= $profileContact !== '' ? ' hidden' : '' ?>>Add your contact number using <strong>Edit details</strong> before checkout.</div>

      <section class="portal-checkout-section">
        <div class="portal-modal-section-head"><div><span class="checkout-section-number" aria-hidden="true">01</span><div><h3>Your items</h3><p>Review products and quantities</p></div></div><small id="portalCheckoutModeLabel">Order now</small></div>
        <div class="portal-checkout-items" id="portalCheckoutItems"></div>
      </section>

      <section class="portal-checkout-section">
        <div class="portal-modal-section-head"><div><span class="checkout-section-number" aria-hidden="true">02</span><div><h3>Pickup or delivery</h3><p>Choose how to receive your order</p></div></div></div>
        <div class="portal-fulfillment-toggle" role="radiogroup" aria-label="Fulfillment method">
          <label><input type="radio" name="fulfillment_method" value="pickup" checked><span><strong>Pickup</strong><small>Collect at the shop</small></span></label>
          <label><input type="radio" name="fulfillment_method" value="delivery"><span><strong>Delivery</strong><small>Send to saved address</small></span></label>
        </div>
        <div class="portal-fulfillment-panel" id="portalPickupPanel">
          <p class="checkout-schedule-helper">Select a date and time for pickup. Times are shown in Philippine time (PHT).</p>
          <label class="checkout-schedule-field"><span>Pickup date</span><input type="date" name="due_date" id="modalPickupDate" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+119 days')) ?>" aria-describedby="pickupAvailabilityStatus"></label>
          <label class="checkout-schedule-field"><span>Pickup time</span><input type="time" name="pickup_time" id="modalPickupTime" step="60" aria-describedby="pickupTimeHint"><small id="pickupTimeHint">Choose your preferred pickup time</small></label>
          <div class="checkout-selected-schedule" id="portalPickupSelection" role="status" aria-live="polite">Select your pickup date and time</div>
        </div>
        <div class="portal-fulfillment-panel portal-delivery-panel" id="portalDeliveryPanel" hidden>
          <p class="checkout-schedule-helper">Choose your preferred delivery date and time. Times are shown in Philippine time (PHT).</p>
          <label class="checkout-schedule-field"><span>Delivery date</span><input type="date" name="delivery_date" id="modalDeliveryDate" aria-describedby="pickupAvailabilityStatus" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+119 days')) ?>"></label>
          <label class="checkout-schedule-field"><span>Delivery time</span><input type="time" name="delivery_time" id="modalDeliveryTime" step="60" aria-describedby="deliveryTimeHint"><small id="deliveryTimeHint">Choose your preferred delivery time</small></label>
          <div class="checkout-selected-schedule" id="portalDeliverySelection" role="status" aria-live="polite">Select your delivery date and time</div>
          <div class="portal-saved-address is-inline">
            <span>Contact number</span>
            <strong id="portalCheckoutSavedContact"><?= htmlspecialchars($profileContact !== '' ? $profileContact : 'No contact number yet.') ?></strong>
            <span>Saved delivery address</span>
            <strong id="portalCheckoutSavedAddress"><?= htmlspecialchars($profileAddress !== '' ? $profileAddress : 'No saved address yet.') ?></strong>
            <small id="portalCheckoutAddressHint" <?= $profileAddress !== '' ? 'hidden' : '' ?>>Add or update your delivery details without leaving checkout.</small>
            <button class="portal-edit-address-link" type="button" id="portalDeliveryEditAddress">Edit details</button>
          </div>
        </div>
        <section class="checkout-availability-card" aria-labelledby="checkoutAvailabilityTitle">
          <div class="checkout-availability-intro">
            <div><h4 id="checkoutAvailabilityTitle">Available pickup dates</h4><p id="checkoutAvailabilityDescription">Find an open date that works for you.</p></div>
            <span class="checkout-availability-live">Date availability</span>
          </div>
          <details class="portal-pickup-calendar-disclosure" id="pickupCalendarDisclosure">
            <summary><span id="checkoutAvailabilityAction">View available pickup dates</span><span class="checkout-availability-chevron" aria-hidden="true">⌄</span></summary>
            <div class="portal-pickup-calendar-wrap" aria-label="Available dates calendar">
              <div class="portal-pickup-calendar-head">
                <button type="button" id="pickupCalendarPrev" aria-label="Previous month">‹</button>
                <strong id="pickupCalendarMonth">Available dates</strong>
                <button type="button" id="pickupCalendarNext" aria-label="Next month">›</button>
              </div>
              <div class="portal-pickup-calendar-weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
              <div class="portal-pickup-calendar" id="pickupAvailabilityCalendar"></div>
              <div class="checkout-availability-legend" aria-label="Date status"><span><i class="available"></i>Available</span><span><i class="full"></i>Full</span><span><i class="closed"></i>Closed</span></div>
            </div>
          </details>
          <p class="portal-pickup-calendar-status" id="pickupAvailabilityStatus" role="status" aria-live="polite">Loading availability…</p>
        </section>
      </section>

      <section class="portal-checkout-section">
        <div class="portal-modal-section-head"><div><span class="checkout-section-number" aria-hidden="true">03</span><div><h3>Order instructions <small class="checkout-optional">Optional</small></h3><p>Add notes or a reference image</p></div></div></div>
        <label class="portal-modal-field"><span>Notes / customization</span><textarea name="notes" rows="3" maxlength="1200" placeholder="Text, size, color, theme, or reminders for the team"></textarea></label>
        <label class="portal-modal-field"><span data-reference-label>Reference Image</span><input type="file" name="reference_image" data-reference-file accept="image/*"><small data-reference-hint hidden>Attach the file you want printed: PDF, Word, PowerPoint, Excel, text or image.</small></label>
      </section>

      <section class="portal-checkout-section">
        <div class="portal-modal-section-head"><div><span class="checkout-section-number" aria-hidden="true">04</span><div><h3>Payment</h3><p>Select your preferred payment method</p></div></div></div>
        <div class="checkout-pay-today"><span id="portalCheckoutPaymentLabel">Downpayment due today <small id="portalCheckoutPaymentNote">25% of order total</small></span><strong id="portalCheckoutDeposit">PHP 0.00</strong></div>
        <div class="portal-payment-options">
          <?php foreach ([
            ['name' => 'GCash', 'icon' => 'G'],
            ['name' => 'Maya', 'icon' => 'M'],
            ['name' => 'Card', 'icon' => '💳'],
          ] as $option): ?>
            <label class="portal-payment-choice">
              <input type="radio" name="payment_method_choice" value="<?= htmlspecialchars($option['name']) ?>" <?= $option['name'] === 'GCash' ? 'checked' : '' ?>>
              <span><i class="payment-option-icon" aria-hidden="true"><?= htmlspecialchars($option['icon']) ?></i><strong><?= htmlspecialchars($option['name']) ?></strong></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="portal-total-box">
          <span>Total <strong id="portalCheckoutTotal">PHP 0.00</strong></span>
          <span id="portalCheckoutBalanceRow">Remaining after downpayment <strong id="portalCheckoutBalance">PHP 0.00</strong></span>
        </div>
      </section>

      <p class="portal-checkout-note">Review your order total, pickup/delivery date, and payment choice before continuing. <a href="../terms-and-conditions.php" target="_blank" rel="noopener noreferrer">Store policies</a></p>
      <div class="portal-checkout-error" id="portalCheckoutError" hidden aria-live="polite"></div>

      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($checkoutCsrf) ?>">
      <input type="hidden" name="cart_payload" id="portalCheckoutPayload" value="[]">
      <div class="portal-modal-footer">
        <button type="submit" class="portal-btn" id="portalCheckoutSubmit" <?= $paymongoAvailable && $profileContact !== '' ? '' : 'disabled aria-disabled="true"' ?>><?= $paymongoAvailable ? 'Continue to Payment' : 'Payment Unavailable' ?></button>
      </div>
    </form>
  </dialog>

  <dialog class="portal-address-modal" id="portalCheckoutAddressModal" aria-labelledby="portalCheckoutAddressTitle">
    <form class="portal-address-card" id="portalCheckoutAddressForm">
      <div class="portal-address-head">
        <div><span class="portal-eyebrow">CONTACT &amp; DELIVERY DETAILS</span><h2 id="portalCheckoutAddressTitle">Edit checkout details</h2><p>Update your contact number and delivery address without leaving checkout.</p></div>
        <button type="button" class="portal-modal-close" id="portalCheckoutAddressClose" aria-label="Close checkout details editor">×</button>
      </div>
      <label class="portal-modal-field" for="portalCheckoutContactInput"><span>Contact number</span>
        <input id="portalCheckoutContactInput" type="tel" name="contact" value="<?= htmlspecialchars($profileContact) ?>" autocomplete="tel" inputmode="tel" maxlength="30" pattern="[0-9+()\-\s]{7,30}" placeholder="09XX XXX XXXX" required>
      </label>
      <label class="portal-modal-field" for="portalCheckoutAddressInput"><span>Complete delivery address <small>(required for delivery)</small></span>
        <textarea id="portalCheckoutAddressInput" name="address" rows="4" maxlength="1000" autocomplete="street-address" placeholder="House number, street, barangay, city / municipality, province"><?= htmlspecialchars($profileAddress) ?></textarea>
      </label>
      <p class="portal-address-error" id="portalCheckoutAddressError" hidden role="alert"></p>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(customerAuthCsrfToken(), ENT_QUOTES) ?>">
      <div class="portal-address-actions"><button type="button" class="portal-btn secondary" id="portalCheckoutAddressCancel">Cancel</button><button type="submit" class="portal-btn" id="portalCheckoutAddressSave">Save details</button></div>
    </form>
  </dialog>

  <dialog class="portal-order-confirm-modal" id="portalOrderConfirmModal" aria-labelledby="portalOrderConfirmTitle">
    <section class="portal-order-confirm-card">
      <div class="portal-confirm-icon" aria-hidden="true">✓</div>
      <span class="portal-eyebrow">Final Check</span>
      <h2 id="portalOrderConfirmTitle">Confirm your order</h2>
      <p class="portal-confirm-copy">Please review the important details below. Once you continue, your order will be created and you will proceed to the selected payment method.</p>

      <div class="portal-confirm-summary">
        <div><span>Items</span><strong id="portalConfirmItems">—</strong></div>
        <div><span>Fulfillment</span><strong id="portalConfirmFulfillment">—</strong></div>
        <div><span>Schedule</span><strong id="portalConfirmSchedule">—</strong></div>
        <div><span>Payment</span><strong id="portalConfirmPayment">—</strong></div>
        <div><span>Total</span><strong id="portalConfirmTotal">PHP 0.00</strong></div>
        <div class="is-highlight"><span id="portalConfirmPaymentDueLabel">25% Downpayment</span><strong id="portalConfirmDeposit">PHP 0.00</strong></div>
      </div>

      <div class="portal-confirm-note">
        <strong>Before you continue</strong>
        <span>Check the quantity, pickup/delivery option, and payment method. You can go back and edit anything before placing the order.</span>
      </div>

      <div class="portal-confirm-actions">
        <button type="button" class="portal-btn secondary" id="portalConfirmBack">Back &amp; Edit</button>
        <button type="button" class="portal-btn" id="portalConfirmProceed">Confirm &amp; Continue</button>
      </div>
    </section>
  </dialog>

  <script>
    window.BrilleShopCheckout = <?= json_encode([
      'profileAddressReady' => $profileAddress !== '',
      'profileContactReady' => $profileContact !== '',
      'paymongoReady' => $paymongoAvailable,
      'checkoutApi' => 'checkout_api.php',
      'pickupCapacityApi' => 'pickup_dates_api.php',
      'shopToday' => date('Y-m-d'),
    ], JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <?php // The Shop already has these rows: keep its cart/Order actions usable even if catalog_api.php fails. ?>
  <script type="application/json" id="portalShopCatalog"><?= json_encode(array_map(static function (array $product) use ($productAvailability, $conn): array {
    return [
      'id' => (int)$product['id'],
      'category_id' => (int)($product['category_id'] ?? 0),
      'name' => (string)$product['name'],
      'description' => (string)($product['description'] ?? ''),
      'category_name' => (string)($product['category_name'] ?? ''),
      'unit_price' => (float)$product['unit_price'],
      'order_mode' => 'regular',
      'size_options' => printshopSizeOptions((string)$product['name'], (float)$product['unit_price'], $conn, (int)$product['id']),
      'image_url' => productDisplayImage($product),
      'sample_image_index' => productSampleImageIndex((string)$product['name']),
      'inventory_id' => (int)($product['inventory_id'] ?? 0),
      'inventory_quantity' => $product['inventory_quantity'] === null ? null : (float)$product['inventory_quantity'],
      'available' => (bool)($productAvailability[(int)$product['id']]['available'] ?? false),
      'unavailable_reason' => (string)($productAvailability[(int)$product['id']]['reason'] ?? 'Availability could not be confirmed.'),
      'max_order_qty' => (int)($productAvailability[(int)$product['id']]['max_order_qty'] ?? 0),
    ];
  }, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
  <script src="../assets/js/customer-commerce.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-commerce.js') ?>"></script>
</body>
</html>
