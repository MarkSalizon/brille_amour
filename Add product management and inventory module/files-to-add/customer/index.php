<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/functions.php';
require_once '../includes/catalog.php';
require_once '../includes/product_images.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$signedInCustomer = loadSignedInCustomer($conn);
ensureCatalogSchema($conn);

$featuredProducts = array_slice(getActiveProductsList($conn), 0, 4);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Brille Amour Customer Ordering</title>
  <script>
    if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
      window.location.replace(<?= json_encode($signedInCustomer ? 'home.php' : '../index.php') ?>);
    }
  </script>
  <link rel="stylesheet" href="../assets/css/product-images.css?v=<?= filemtime(__DIR__ . '/../assets/css/product-images.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-landing.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-landing.css') ?>">
  <link rel="stylesheet" href="../assets/css/design-system.css?v=<?= filemtime(__DIR__ . '/../assets/css/design-system.css') ?>">
  <link rel="stylesheet" href="../assets/css/brille-ui-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-ui-polish.css') ?>">
  <link rel="stylesheet" href="../assets/css/brille-mobile-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-mobile-polish.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-chatbot.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-chatbot.css') ?>">
<link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-compat.css') ?>">
<link rel="stylesheet" href="../assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . "/../assets/css/system-ux-v2.css") ?>">
<link rel="stylesheet" href="../assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-comfort.css') ?>">
<link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>

<body>
  <main class="customer-landing">
    <header class="landing-header">
      <a class="brand" href="../index.php">
        <img src="../assets/img/image.png" alt="Brille Amour logo">
        <span>
          <strong>Brille Amour</strong>
          <small>Custom Prints and Crafts</small>
        </span>
      </a>
      <nav aria-label="Customer links">
        <a href="#products">Products</a>
        <a href="#process">How It Works</a>
        <a href="track.php">Track Order</a>
        <?php if ($signedInCustomer): ?>
          <a class="nav-account" href="account.php">My Account</a>
        <?php else: ?>
          <a class="nav-account" href="login.php">Login</a>
        <?php endif; ?>
        <a class="nav-order" href="order.php">Order Now</a>
      </nav>
    </header>

    <section class="hero">
      <div class="hero-copy">
        <p class="eyebrow">Online Customer Ordering</p>
        <h1>Printing services and custom merchandise, all in one place.</h1>
        <p class="intro">Start a Brille Amour order from your phone or computer. Pick products, add design details, upload references, and send everything to the production team.</p>
      <div class="hero-actions">
        <a class="btn primary" href="order.php">Start Order</a>
        <a class="btn secondary" href="track.php">Track Order</a>
      </div>
      </div>
      <div class="showcase" aria-label="Featured services">
        <article>
          <span>01</span>
          <strong>Print Orders</strong>
          <small>Cards, flyers, posters, stickers, labels, and invitations.</small>
        </article>
        <article>
          <span>02</span>
          <strong>Personalized Items</strong>
          <small>Customized shirts, mugs, keychains, pins, and photo magnets.</small>
        </article>
        <article>
          <span>03</span>
          <strong>Photo & Document Prints</strong>
          <small>Photo prints, ID pictures, document printing, and lamination.</small>
        </article>
      </div>
    </section>

    <section class="featured-section" id="products">
      <div class="section-heading">
        <p class="eyebrow">Popular Picks</p>
        <h2>Explore printing services</h2>
      </div>
      <div class="featured-grid">
        <?php foreach ($featuredProducts as $product):
          $initial = strtoupper(substr((string)$product['name'], 0, 1));
        ?>
          <article class="featured-card">
            <?php $sampleIndex = productSampleImageIndex((string)$product['name']);
          $previewImage = productDisplayImage($product); ?>
            <?php if ($previewImage !== null): ?>
              <img class="catalog-product-preview" src="../<?= htmlspecialchars($previewImage, ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string)$product['name']) ?> product sample" loading="lazy">
            <?php elseif ($sampleIndex !== null): ?>
              <div class="product-sample">
                <div class="product-sample-image" role="img" aria-label="<?= htmlspecialchars($product['name'] . ' sample design') ?>" style="background-position: <?= ($sampleIndex % 5) * 25 ?>% <?= floor($sampleIndex / 5) * 100 / 3 ?>%"></div>
                <small>Sample design</small>
              </div>
            <?php else: ?>
              <div class="product-mark" aria-hidden="true"><?= htmlspecialchars($initial) ?></div>
            <?php endif; ?>
            <span><?= htmlspecialchars($product['category_name'] ?? 'Custom Product') ?></span>
            <h3><?= htmlspecialchars($product['name']) ?></h3>
            <p><?= htmlspecialchars($product['description'] ?: 'Customized for your preferred design and occasion.') ?></p>
            <strong><?= (float)$product['unit_price'] > 0 ? formatMoney((float)$product['unit_price']) : 'Price on request' ?></strong>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="process-section" id="process">
      <div class="section-heading">
        <p class="eyebrow">How It Works</p>
        <h2>Order in three quick steps</h2>
      </div>
      <div class="process-grid">
        <article>
          <b>1</b>
          <strong>Choose Products</strong>
          <span>Add the custom items you need to your cart.</span>
        </article>
        <article>
          <b>2</b>
          <strong>Send Details</strong>
          <span>Enter contact details, choose a schedule, add notes, and attach an optional reference image.</span>
        </article>
        <article>
          <b>3</b>
          <strong>Wait for Confirmation</strong>
          <span>The Brille Amour team confirms artwork, payment, and schedule.</span>
        </article>
      </div>
    </section>

    <section class="cta-band">
      <div>
        <p class="eyebrow">Ready When You Are</p>
        <h2>Build your cart now.</h2>
      </div>
      <a class="btn primary" href="order.php">Open Order Menu</a>
    </section>
    <footer style="padding: 24px 0; text-align: center;">
      <small>&copy; <?= date('Y') ?> Brille Amour Prints &amp; Crafts &middot; <a href="../privacy-policy.php" style="text-decoration: underline;">Privacy Policy</a> &middot; <a href="../terms-and-conditions.php" style="text-decoration: underline;">Terms and Conditions</a></small>
    </footer>
  </main>
  <?php require __DIR__ . '/../includes/customer_chatbot_widget.php'; ?>
  <script src="../assets/js/customer-chatbot.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-chatbot.js') ?>"></script>
  <script src="../assets/js/customer-floating-hub.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-floating-hub.js') ?>"></script>
  <script src="../assets/js/mobile-polish.js?v=<?= filemtime(__DIR__ . '/../assets/js/mobile-polish.js') ?>"></script>
<script src="../assets/js/public-ajax.js?v=<?= filemtime(__DIR__ . "/../assets/js/public-ajax.js") ?>"></script>
  <script src="../assets/js/customer-featured-live.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-featured-live.js') ?>"></script>
</body>

</html>
