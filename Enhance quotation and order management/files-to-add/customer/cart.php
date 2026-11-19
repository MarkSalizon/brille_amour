<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) { header('Location: ../index.php?auth=login'); exit; }
$portalActive = 'cart';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Carts - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/product-images.css?v=<?= filemtime(__DIR__ . '/../assets/css/product-images.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-cart.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-cart.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-size-options.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-size-options.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-cart-mobile-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-cart-mobile-fix.css') ?>">
</head>
<body class="customer-portal-body" data-commerce-page="cart">
  <?php require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-portal-main customer-cart-page">
    <header class="cart-page-header">
      <div>
        <span class="portal-eyebrow">YOUR BAG</span>
        <h1>Shopping Cart</h1>
        <p>Review your picks before placing your order.</p>
      </div>
      <a class="cart-shop-link" href="shop.php" aria-label="Continue shopping">Continue shopping <span aria-hidden="true">→</span></a>
    </header>

    <p id="portalCartLoading" class="cart-loading" role="status">Loading your cart...</p>
    <section class="portal-cart-layout" aria-label="Shopping cart">
      <div class="cart-items-panel">
        <div class="cart-items-heading" id="portalCartItemsHeading" hidden>
          <h2>Cart items <span id="portalCartItemCount" aria-live="polite">0 items</span></h2>
          <label class="cart-select-all"><input type="checkbox" id="portalCartSelectAll" checked><span>Select all</span></label>
        </div>
        <div class="portal-cart-list" id="portalCartList"></div>
        <section class="portal-empty-card cart-empty-state" id="portalCartEmpty" hidden>
          <span class="cart-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2 11h12l2-8H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg></span>
          <h2>Your cart is empty</h2>
          <p>Find something you love in our shop and add it to your cart.</p>
          <a class="portal-btn" href="shop.php">Browse products <span aria-hidden="true">→</span></a>
        </section>
      </div>
      <aside class="portal-cart-summary" id="portalCartSummary" hidden aria-label="Order summary">
        <h2>Order summary</h2>
        <p class="cart-selected-summary" id="portalCartSelectedCount" aria-live="polite">Choose items to checkout</p>
        <div class="portal-summary-row"><span>Subtotal</span><strong id="portalCartSubtotal">PHP 0.00</strong></div>
        <div class="portal-summary-row"><span>Downpayment (25%)</span><strong id="portalCartDeposit">PHP 0.00</strong></div>
        <div class="portal-summary-row total"><span>Balance after downpayment</span><strong id="portalCartBalance">PHP 0.00</strong></div>
        <p class="cart-summary-info">Delivery or pickup details and the final total are confirmed at checkout.</p>
        <a class="portal-btn cart-checkout-button" id="portalCheckoutButton" href="shop.php?checkout=cart">Proceed to checkout <span aria-hidden="true">→</span></a>
        <p class="portal-cart-note">Your saved account details will be used at checkout.</p>
      </aside>
    </section>
  </main>
  <script src="../assets/js/customer-commerce.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-commerce.js') ?>"></script>
</body>
</html>
