<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title>Team Portal | Brille Amour</title>
  <link rel="stylesheet" href="assets/css/team-portal.css?v=<?= filemtime(__DIR__ . '/assets/css/team-portal.css') ?>">
  <link rel="stylesheet" href="assets/css/brille-ui-polish.css?v=20260909">
  <link rel="stylesheet" href="assets/css/brille-mobile-polish.css?v=20260909">
  <link rel="stylesheet" href="assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/assets/css/typography-clean.css') ?>">
  <link rel="stylesheet" href="assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/assets/css/mobile-compat.css') ?>">
  <link rel="stylesheet" href="assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . '/assets/css/system-ux-v2.css') ?>">
  <link rel="stylesheet" href="assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/assets/css/mobile-comfort.css') ?>">
  <link rel="stylesheet" href="assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/assets/css/mobile-system-final.css') ?>">
  <link rel="stylesheet" href="assets/css/team-portal-v2.css?v=<?= filemtime(__DIR__ . '/assets/css/team-portal-v2.css') ?>">
</head>
<body class="team-portal-page portal-v2">
  <?php $welcomeSplashMode = 'entry'; $welcomeSplashLogo = 'assets/img/image.png'; require __DIR__ . '/includes/welcome_splash.php'; ?>

  <div class="portal-shell-v2">
    <header class="portal-header-v2">
      <a class="portal-brand-v2" href="portal.php" aria-label="Brille Amour team portal">
        <img src="assets/img/image.png" alt="Brille Amour logo">
        <span class="portal-brand-copy-v2">
          <strong>Brille Amour</strong>
          <small>Team Portal</small>
        </span>
      </a>

      <nav class="portal-quick-nav-v2" aria-label="Team login shortcuts">
        <a href="admin/login.php?require_pin=1">Admin</a>
        <a href="staff/login.php">Staff</a>
      </nav>
    </header>

    <main class="portal-main-v2" id="main">
      <section class="portal-hero-v2" aria-labelledby="portal-title">
        <div class="portal-hero-copy-v2">
          <span class="portal-kicker-v2">BRILLÉ AMOUR · TEAM WORKSPACE</span>
          <h1 id="portal-title">Keep every order moving, <em>together.</em></h1>
          <p>One organized workspace for managing orders, production, inventory, customers, and day-to-day team tasks.</p>

          <div class="portal-feature-pills-v2" aria-label="Portal capabilities">
            <span>Orders</span>
            <span>Production</span>
            <span>Inventory</span>
            <span>Reports</span>
          </div>
        </div>

        <div class="portal-hero-mark-v2" aria-hidden="true">
          <img src="assets/img/image.png" alt="">
          <span>PRINTS &amp; CRAFTS</span>
        </div>
      </section>

      <section class="portal-workspaces-v2" aria-labelledby="workspace-title">
        <div class="portal-section-head-v2">
          <div>
            <span class="portal-section-kicker-v2">CHOOSE YOUR WORKSPACE</span>
            <h2 id="workspace-title">Where are you signing in?</h2>
          </div>
          <p>Select the workspace that matches your role.</p>
        </div>

        <div class="portal-role-grid-v2">
          <article class="portal-role-card-v2 portal-admin-card-v2">
            <div class="portal-role-head-v2">
              <span class="portal-role-icon-v2" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>
              </span>
              <span class="portal-role-badge-v2">ADMIN</span>
            </div>

            <div class="portal-role-copy-v2">
              <h3>Run the whole operation.</h3>
              <p>Monitor the business, verify transactions, manage products and inventory, and keep the team aligned.</p>
            </div>

            <div class="portal-capability-grid-v2" aria-label="Admin capabilities">
              <span>Orders &amp; payments</span>
              <span>Products &amp; inventory</span>
              <span>Reports &amp; feedback</span>
              <span>Team management</span>
            </div>

            <a class="portal-primary-action-v2" href="admin/login.php?require_pin=1">
              <span>Continue as Admin</span>
              <span aria-hidden="true">→</span>
            </a>
          </article>

          <article class="portal-role-card-v2 portal-staff-card-v2">
            <div class="portal-role-head-v2">
              <span class="portal-role-icon-v2" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="16" rx="2"/><rect x="9" y="2" width="6" height="5" rx="1"/><path d="m9 13 2 2 4-4M9 18h6"/></svg>
              </span>
              <span class="portal-role-badge-v2">STAFF</span>
            </div>

            <div class="portal-role-copy-v2">
              <h3>Focus on today’s work.</h3>
              <p>See assigned orders, update production progress, check inventory, messages, attendance, and your profile.</p>
            </div>

            <div class="portal-capability-grid-v2" aria-label="Staff capabilities">
              <span>Production tasks</span>
              <span>Inventory checks</span>
              <span>Messages</span>
              <span>Attendance</span>
            </div>

            <a class="portal-primary-action-v2 portal-staff-action-v2" href="staff/login.php">
              <span>Continue as Staff</span>
              <span aria-hidden="true">→</span>
            </a>
          </article>
        </div>
      </section>

      <aside class="portal-customer-banner-v2" aria-label="Customer website">
        <div>
          <span class="portal-customer-icon-v2" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/><path d="M9 21v-6h6v6"/></svg>
          </span>
          <span><strong>Looking for the customer website?</strong><small>Browse products, place orders, and track your purchases.</small></span>
        </div>
        <a href="index.php">Go to customer site <span aria-hidden="true">↗</span></a>
      </aside>
    </main>

    <footer class="portal-footer-v2">
      <span>© <?= date('Y') ?> Brille Amour Prints &amp; Crafts</span>
      <span>Built for a smoother team workflow.</span>
    </footer>
  </div>

  <script src="assets/js/mobile-polish.js?v=20260909" defer></script>
</body>
</html>
