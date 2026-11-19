<?php
// NO session_start() here — page already handled it
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Don't call requireLogin() here — each page already checked role
$user    = currentUser();
$isAdmin = isAdmin();

if ($isAdmin) {
    if (!isset($conn)) {
        require_once __DIR__ . '/db.php';
    }
    require_once __DIR__ . '/staff_roles.php';
    require_once __DIR__ . '/admin_settings.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/messaging.php';
    ensureCheckoutPaymentSchema($conn);
    ensureMessagingSchema($conn);

    $acHere = basename($_SERVER['PHP_SELF'] ?? '');
    $acHome = $acHere === 'dashboard.php';

    // ── sidebar counters: one round trip ──
    $acBadge = ['pending_orders' => 0, 'pending_payments' => 0, 'in_prod' => 0, 'low_stock' => 0, 'access_reqs' => 0, 'messages' => 0];
    $acRes = $conn->query("SELECT
        (SELECT COUNT(*) FROM orders    WHERE status = 'pending')                              AS pending_orders,
        (SELECT COUNT(*) FROM checkout_payments WHERE status = 'pending')                       AS pending_payments,
        (SELECT COUNT(*) FROM orders    WHERE status IN ('in_production','quality_check'))     AS in_prod,
        (SELECT COUNT(*) FROM inventory WHERE status = 'active' AND quantity <= reorder_level) AS low_stock,
        (SELECT COUNT(*) FROM users     WHERE status = 'inactive')                             AS access_reqs");
    if ($acRes) {
        $acBadge = array_merge($acBadge, array_map('intval', $acRes->fetch_assoc()));
        $acRes->free();
    }
    $acBadge['messages'] = unreadChatTotal($conn, (int)($user['id'] ?? 0));

    /** Inline nav / UI icon. Stroke-only, inherits currentColor. */
    function acIcon(string $name): string
    {
        $p = [
            'overview'   => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
            'orders'     => '<path d="M8 3h8a1 1 0 0 1 1 1v1h1.5A1.5 1.5 0 0 1 20 6.5v13A1.5 1.5 0 0 1 18.5 21h-13A1.5 1.5 0 0 1 4 19.5v-13A1.5 1.5 0 0 1 5.5 5H7V4a1 1 0 0 1 1-1z"/><path d="M8.5 11h7M8.5 15h4.5"/>',
            'production' => '<circle cx="12" cy="12" r="3.1"/><path d="M12 2.6v3M12 18.4v3M4.4 12H1.4M22.6 12h-3M6.6 6.6 4.5 4.5M19.5 19.5l-2.1-2.1M17.4 6.6l2.1-2.1M4.5 19.5l2.1-2.1"/>',
            'inventory'  => '<path d="M20.5 8.2v7.6a1.5 1.5 0 0 1-.79 1.32l-6.98 3.8a1.5 1.5 0 0 1-1.46 0l-6.98-3.8A1.5 1.5 0 0 1 3.5 15.8V8.2a1.5 1.5 0 0 1 .79-1.32l6.98-3.8a1.5 1.5 0 0 1 1.46 0l6.98 3.8A1.5 1.5 0 0 1 20.5 8.2z"/><path d="m3.8 7.4 8.2 4.4 8.2-4.4M12 20.6v-8.8"/>',
            'reports'    => '<path d="M4 20V4M4 20h16"/><path d="M8.5 20v-6.5M13 20V8.5M17.5 20v-9.5"/>',
            'teams'      => '<circle cx="9" cy="8" r="3.2"/><path d="M2.8 19.4a6.2 6.2 0 0 1 12.4 0"/><path d="M16.4 5.2a3.2 3.2 0 0 1 0 5.9M18 14.4a5.6 5.6 0 0 1 3.3 4.4"/>',
            'customers'  => '<path d="M12 20.4s-7.6-4.4-7.6-9.7a4.3 4.3 0 0 1 7.6-2.8 4.3 4.3 0 0 1 7.6 2.8c0 5.3-7.6 9.7-7.6 9.7z"/>',
            'messages'   => '<path d="M4 5.5h16a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5H8.2L4 20.2V7a1.5 1.5 0 0 1 1.5-1.5z"/><path d="M8 10h8M8 13.5h5.5"/>',
            'roles'      => '<path d="M12 2.9l7.4 2.9v5.6c0 4.5-3.1 8.4-7.4 9.7-4.3-1.3-7.4-5.2-7.4-9.7V5.8z"/><path d="m9.1 12 2 2 3.8-3.9"/>',
            'settings'   => '<circle cx="12" cy="12" r="2.9"/><path d="M19.2 14.6a1.5 1.5 0 0 0 .3 1.65l.06.06a1.8 1.8 0 1 1-2.55 2.55l-.06-.06a1.5 1.5 0 0 0-1.65-.3 1.5 1.5 0 0 0-.9 1.37V20a1.8 1.8 0 1 1-3.6 0v-.1a1.5 1.5 0 0 0-.98-1.37 1.5 1.5 0 0 0-1.65.3l-.06.06a1.8 1.8 0 1 1-2.55-2.55l.06-.06a1.5 1.5 0 0 0 .3-1.65 1.5 1.5 0 0 0-1.37-.9H4a1.8 1.8 0 1 1 0-3.6h.1a1.5 1.5 0 0 0 1.37-.98 1.5 1.5 0 0 0-.3-1.65l-.06-.06A1.8 1.8 0 1 1 7.66 4.9l.06.06a1.5 1.5 0 0 0 1.65.3H9.4a1.5 1.5 0 0 0 .9-1.37V4a1.8 1.8 0 1 1 3.6 0v.1a1.5 1.5 0 0 0 .9 1.37 1.5 1.5 0 0 0 1.65-.3l.06-.06a1.8 1.8 0 1 1 2.55 2.55l-.06.06a1.5 1.5 0 0 0-.3 1.65v.08a1.5 1.5 0 0 0 1.37.9H20a1.8 1.8 0 1 1 0 3.6h-.1a1.5 1.5 0 0 0-1.37.9z"/>',
            'profile'    => '<circle cx="12" cy="8.2" r="3.6"/><path d="M4.8 20.2a7.2 7.2 0 0 1 14.4 0"/>',
            'logout'     => '<path d="M9.5 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3.5"/><path d="M15.5 8.2 19.5 12l-4 3.8M19 12H9.6"/>',
            'menu'       => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'chevron'    => '<path d="m5 8.5 5 5 5-5" transform="translate(2 1)"/>',
        ];
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
    }

    /** One sidebar link. $count renders a rose pill (same style for every item; $alert kept for compatibility). */
    function acNav(string $href, string $icon, string $label, int $count = 0, bool $alert = false): string
    {
        $active = basename($_SERVER['PHP_SELF'] ?? '') === $href ? ' is-active' : '';
        $pill   = $count > 0
            ? '<span class="ac-nav-count is-alert">' . ($count > 99 ? '99+' : $count) . '</span>'
            : '';
        return '<a class="ac-nav-item' . $active . '" href="' . $href . '">'
            . acIcon($icon)
            . '<span class="ac-nav-text">' . htmlspecialchars($label) . '</span>'
            . $pill . '</a>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $pageTitle ?? SITE_NAME ?> &mdash; <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php if ($isAdmin): ?>
<link rel="stylesheet" href="../assets/css/admin-console.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-console.css') ?>">
<link rel="stylesheet" href="../assets/css/brille-ui-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-ui-polish.css') ?>">
<link rel="stylesheet" href="../assets/css/brille-mobile-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-mobile-polish.css') ?>">
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php if ($isAdmin): ?>
<script defer src="../assets/js/messaging.js"></script>
<?php endif; ?>
<link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/notification-dropdown-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/notification-dropdown-fix.css') ?>">
<?php if ($isAdmin && ($pageTitle ?? '') === 'Admin Overview'): ?>
<link rel="stylesheet" href="../assets/css/admin-overview-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-overview-clean.css') ?>">
<?php endif; ?>
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<?php
$bodyClasses = [];
if ($isAdmin) $bodyClasses[] = 'ac-body';
if (!empty($bodyClass ?? '')) {
    foreach (preg_split('/\s+/', trim((string)$bodyClass)) as $className) {
        if ($className !== '') $bodyClasses[] = $className;
    }
}
?>
<body<?= $bodyClasses ? ' class="' . htmlspecialchars(implode(' ', array_unique($bodyClasses)), ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
<?php if (!empty($_SESSION['ba_team_welcome_pending'])):
    unset($_SESSION['ba_team_welcome_pending']);
    $welcomeSplashMode = 'login';
    $welcomeSplashLogo = '../assets/img/image.png';
    require __DIR__ . '/welcome_splash.php';
endif; ?>
<?php if ($isAdmin): ?>
<div class="layout">
<div class="ac-scrim" id="acScrim" aria-hidden="true"></div>

<aside class="sidebar" id="acSidebar">
  <a class="ac-brand" href="dashboard.php">
    <span class="ac-brand-mark">BA</span>
    <span class="ac-brand-copy">
      <span class="ac-brand-name"><?= htmlspecialchars(getSetting($conn, 'business_name', SITE_NAME)) ?></span>
      <span class="ac-brand-sub">Control Center</span>
    </span>
  </a>

  <nav class="ac-nav" aria-label="Admin navigation">
    <div class="ac-nav-label">Operations</div>
    <?= acNav('dashboard.php',  'overview',   'Admin Overview') ?>
    <?= acNav('orders.php',     'orders',     'Orders',              $acBadge['pending_orders']) ?>
    <?= acNav('payments.php',   'orders',     'Payments',      $acBadge['pending_payments'], true) ?>
    <?= acNav('pickup-capacity.php', 'reports', 'Pickup / Delivery Schedules') ?>
    <?= acNav('production.php', 'production', 'Production Progress', $acBadge['in_prod']) ?>
    <?= acNav('inventory.php',  'inventory',  'Inventory',           $acBadge['low_stock'], true) ?>
    <?= acNav('products.php', 'inventory', 'Products') ?>
    <?= acNav('reports.php',    'reports',    'Reports') ?>

    <div class="ac-nav-label">Organization</div>
    <?= acNav('users.php',     'teams',     'Teams / Users',        $acBadge['access_reqs'], true) ?>
    <?= acNav('customers.php', 'customers', 'Customer Management') ?>

  </nav>

  <div class="ac-sidebar-foot">
    <div class="ac-sidebar-user">
      <span class="ac-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></span>
      <span class="ac-sidebar-user-copy">
        <strong><?= htmlspecialchars($user['full_name']) ?></strong>
        <small>Administrator</small>
      </span>
    </div>
  </div>
</aside>

<div class="main-content">
<header class="topbar">
  <div class="ac-head-left">
    <button type="button" class="ac-nav-toggle" id="acNavToggle" aria-label="Open navigation" aria-controls="acSidebar" aria-expanded="false">
      <?= acIcon('menu') ?>
    </button>
    <div>
      <?php if (!$acHome): ?>
        <div class="ac-brand-sub" style="color:var(--ac-ink-3)">Admin Control Center</div>
      <?php endif; ?>
      <h1 class="ac-head-title"><?= $acHome ? 'Admin Control Center' : htmlspecialchars($pageTitle ?? 'Admin') ?></h1>
      <div class="ac-head-meta">
        <time id="acClock" datetime="<?= date('c') ?>"><?= date('D, j M Y · g:i A') ?></time>
      </div>
    </div>
  </div>

  <div class="ac-head-right">
    <?php include __DIR__ . '/messaging_widget.php'; ?>

    <a class="ac-icon-btn" href="settings.php" aria-label="System settings" title="System settings"><?= acIcon('settings') ?></a>

    <div class="ac-profile" id="acProfile">
      <button type="button" class="ac-profile-btn" id="acProfileBtn" aria-expanded="false" aria-haspopup="true">
        <span class="ac-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></span>
        <span class="ac-profile-name">
          <strong><?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?></strong>
          <small>Admin</small>
        </span>
        <svg class="ac-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9.5 6 6 6-6"/></svg>
      </button>
      <div class="ac-menu" id="acProfileMenu" role="menu" hidden>
        <div class="ac-menu-head">
          <strong><?= htmlspecialchars($user['full_name']) ?></strong>
          <small><?= htmlspecialchars(($user['email'] ?? '') ?: '@' . $user['username']) ?></small>
        </div>
        <a href="profile.php" role="menuitem"><?= acIcon('profile') ?> My Profile</a>
        <div class="ac-menu-sep"></div>
        <form method="post" action="logout.php">
          <button type="submit" role="menuitem"><?= acIcon('logout') ?> Sign Out</button>
        </form>
      </div>
    </div>
  </div>
</header>
<div class="page-content">

<script>
/* Admin shell behaviour: profile menu, off-canvas nav, live clock. */
(function () {
  var btn = document.getElementById('acProfileBtn'),
      menu = document.getElementById('acProfileMenu');
  if (btn && menu) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !menu.hidden;
      menu.hidden = open;
      btn.setAttribute('aria-expanded', String(!open));
    });
    document.addEventListener('click', function (e) {
      if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) {
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hidden) {
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    });
  }

  var toggle = document.getElementById('acNavToggle'),
      scrim = document.getElementById('acScrim');
  function closeNav() {
    var sidebar = document.getElementById('acSidebar');
    if (toggle && sidebar && sidebar.contains(document.activeElement)) toggle.focus();
    document.body.classList.remove('ac-nav-open');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open navigation');
    }
  }
  if (toggle) {
    toggle.addEventListener('click', function () {
      var open = document.body.classList.toggle('ac-nav-open');
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    });
  }
  if (scrim) scrim.addEventListener('click', closeNav);
  document.querySelectorAll('#acSidebar a').forEach(function (link) {
    link.addEventListener('click', closeNav);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });

  /* Clock ticks in the shop's timezone regardless of the viewer's machine. */
  var clock = document.getElementById('acClock');
  if (clock) {
    var fmt = new Intl.DateTimeFormat('en-GB', {
      timeZone: <?= json_encode(APP_TIMEZONE) ?>, weekday: 'short', day: 'numeric',
      month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true
    });
    setInterval(function () {
      var p = {};
      fmt.formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
      clock.textContent = p.weekday + ', ' + p.day + ' ' + p.month + ' ' + p.year +
                          ' · ' + p.hour + ':' + p.minute + ' ' + (p.dayPeriod || '').toUpperCase();
    }, 20000);
  }
})();
</script>
<?php else: ?>
<div class="layout">
<div class="nav-scrim" id="baScrim" aria-hidden="true"></div>
<aside class="sidebar" id="baSidebar">
  <div class="sidebar-brand">
    <div class="logo-text">Brille Amour</div>
    <div class="logo-sub">Production Management</div>
  </div>
  <div class="sidebar-user">
    <div class="avatar"><?= strtoupper(substr($user['full_name'],0,1)) ?></div>
    <div class="user-info">
      <div class="name"><?= htmlspecialchars($user['full_name']) ?></div>
      <div class="role"><?= ucfirst($user['role']) ?></div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
      <a class="nav-item" href="dashboard.php"><span class="icon">⊞</span> Dashboard</a>
      <a class="nav-item" href="orders.php">Orders</a>
      <a class="nav-item" href="production.php">Production</a>
      <a class="nav-item" href="inventory.php">Inventory</a>
      <div class="nav-section-label">Account</div>
      <a class="nav-item" href="profile.php">My Profile</a>
  </nav>
  <div class="sidebar-footer">
    <form method="post" action="logout.php">
      <button type="submit" class="logout-btn">⎋ &nbsp; Sign Out</button>
    </form>
  </div>
</aside>
<div class="main-content">
<div class="topbar">
  <div class="topbar-left">
    <button type="button" class="nav-toggle" id="baNavToggle" aria-label="Open navigation" aria-expanded="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <div class="topbar-title"><?= $pageTitle ?? 'Dashboard' ?></div>
  </div>
  <div class="topbar-right">
    <span class="text-sm text-muted"><?= date('D, M j Y') ?></span>
  </div>
</div>
<div class="page-content">

<script>
(function () {
  var toggle = document.getElementById('baNavToggle'),
      scrim  = document.getElementById('baScrim');
  function closeNav() {
    document.body.classList.remove('nav-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
  }
  if (toggle) {
    toggle.addEventListener('click', function () {
      var open = document.body.classList.toggle('nav-open');
      toggle.setAttribute('aria-expanded', String(open));
    });
  }
  if (scrim) scrim.addEventListener('click', closeNav);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });
  document.querySelectorAll('#baSidebar a, #baSidebar form button').forEach(function (el) {
    el.addEventListener('click', closeNav);
  });
})();
</script>
<?php endif; ?>
