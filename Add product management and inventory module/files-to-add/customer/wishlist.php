<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) { header('Location: ../index.php?auth=login'); exit; }
$portalActive = 'wishlist';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Wishlists - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/product-images.css?v=<?= filemtime(__DIR__ . '/../assets/css/product-images.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
</head>
<body class="customer-portal-body" data-commerce-page="wishlist">
  <?php require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-portal-main">
    <section class="portal-page-heading"><div><span class="portal-eyebrow">Saved for later</span><h1>Wishlists</h1><p>Keep the Brille Amour products you like here, then add them to your cart when you are ready.</p></div><a class="portal-btn" href="shop.php">Continue Shopping</a></section>
    <p id="wishlistLoading" style="color:#79656f">Loading your saved products...</p>
    <section class="portal-wishlist-grid" id="wishlistGrid"></section>
    <section class="portal-empty-card" id="wishlistEmpty" hidden><strong>Your wishlist is empty.</strong><p>Tap the heart on any product in the Shop to save it here.</p><a class="portal-btn" href="shop.php">Browse Shop</a></section>
  </main>
  <script src="../assets/js/customer-commerce.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-commerce.js') ?>"></script>
</body>
</html>
