<?php
$authShowcaseBadge = $authShowcaseBadge ?? 'Brille Amour Product Suite';
$authShowcaseTitle = $authShowcaseTitle ?? 'A showcase for every custom order.';
$authShowcaseText = $authShowcaseText ?? 'Flyers, stickers, invitations, mugs, and custom shirts all live in one polished workflow.';
?>
<aside class="auth-showcase">
  <div class="auth-showcase-copy">
    <div class="auth-showcase-badge"><?= htmlspecialchars($authShowcaseBadge) ?></div>
    <h2 class="auth-showcase-title"><?= htmlspecialchars($authShowcaseTitle) ?></h2>
    <p class="auth-showcase-text"><?= htmlspecialchars($authShowcaseText) ?></p>
  </div>

  <div class="auth-product-board" aria-hidden="true">
    <article class="product-card product-card-banner">
      <span class="product-card-kicker">Printing</span>
      <strong>Tarpaulin</strong>
      <span class="product-card-note">Large-format event displays</span>
      <span class="product-visual product-visual-banner"></span>
    </article>

    <article class="product-card product-card-flyers">
      <span class="product-card-kicker">Paper Goods</span>
      <strong>Flyers</strong>
      <span class="product-card-note">Quick promos and handouts</span>
      <span class="product-visual product-visual-flyers"></span>
    </article>

    <article class="product-card product-card-stickers">
      <span class="product-card-kicker">Custom Cuts</span>
      <strong>Stickers</strong>
      <span class="product-card-note">Seals, packs, and labels</span>
      <span class="product-visual product-visual-stickers"></span>
    </article>

    <article class="product-card product-card-invitations">
      <span class="product-card-kicker">Events</span>
      <strong>Invitations</strong>
      <span class="product-card-note">Elegant announcements</span>
      <span class="product-visual product-visual-invitations"></span>
    </article>

    <article class="product-card product-card-mugs">
      <span class="product-card-kicker">Gifts</span>
      <strong>Mugs</strong>
      <span class="product-card-note">Printed keepsakes</span>
      <span class="product-visual product-visual-mugs"></span>
    </article>

    <article class="product-card product-card-shirts">
      <span class="product-card-kicker">Wearables</span>
      <strong>Custom T-Shirts</strong>
      <span class="product-card-note">Branded apparel and uniforms</span>
      <span class="product-visual product-visual-shirts"></span>
    </article>
  </div>

  <div class="auth-showcase-tags" aria-label="Featured product types">
    <span>Brochures</span>
    <span>Party Banners</span>
    <span>Labels</span>
    <span>IDs</span>
    <span>Lanyards</span>
    <span>Sticker Packs</span>
  </div>
</aside>
