<?php
// NO session_start() here — staff page already handled it
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/staff_order_acceptance.php';

if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}

$user    = currentUser();
$isAdmin = isAdmin();
$staffUserId = (int)($user['id'] ?? 0);

ensureMessagingSchema($conn);

$staffNotifCount = $staffUserId > 0 ? unreadNotificationCount($conn, $staffUserId) : 0;
$staffChatCount = $staffUserId > 0 ? unreadChatTotal($conn, $staffUserId) : 0;
$staffIncomingCount = $staffUserId > 0 ? staffIncomingOrderCount($conn, $staffUserId) : 0;

/** Inline nav icon. Stroke-only, inherits currentColor — kept consistent with the admin sidebar. */
function baIcon(string $name): string
{
    $p = [
        'production'    => '<circle cx="12" cy="12" r="3.1"/><path d="M12 2.6v3M12 18.4v3M4.4 12H1.4M22.6 12h-3M6.6 6.6 4.5 4.5M19.5 19.5l-2.1-2.1M17.4 6.6l2.1-2.1M4.5 19.5l2.1-2.1"/>',
        'inventory'     => '<path d="M20.5 8.2v7.6a1.5 1.5 0 0 1-.79 1.32l-6.98 3.8a1.5 1.5 0 0 1-1.46 0l-6.98-3.8A1.5 1.5 0 0 1 3.5 15.8V8.2a1.5 1.5 0 0 1 .79-1.32l6.98-3.8a1.5 1.5 0 0 1 1.46 0l6.98 3.8A1.5 1.5 0 0 1 20.5 8.2z"/><path d="m3.8 7.4 8.2 4.4 8.2-4.4M12 20.6v-8.8"/>',
        'attendance'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/><path d="M7.5 3.2 5.8 5M16.5 3.2 18.2 5"/>',
        'messages'      => '<path d="M4 5.5h16A1.5 1.5 0 0 1 21.5 7v9A1.5 1.5 0 0 1 20 17.5H8l-4.5 4V7A1.5 1.5 0 0 1 4 5.5z"/>',
        'notifications' => '<path d="M12 3.5a5 5 0 0 0-5 5v3.2c0 .9-.3 1.8-.9 2.5L4.7 16h14.6l-1.4-1.8a4 4 0 0 1-.9-2.5V8.5a5 5 0 0 0-5-5z"/><path d="M9.6 19.5a2.4 2.4 0 0 0 4.8 0"/>',
        'profile'       => '<circle cx="12" cy="8.2" r="3.6"/><path d="M4.8 20.2a7.2 7.2 0 0 1 14.4 0"/>',
        'logout'        => '<path d="M9.5 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3.5"/><path d="M15.5 8.2 19.5 12l-4 3.8M19 12H9.6"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $pageTitle ?? SITE_NAME ?> &mdash; <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="../assets/css/brille-ui-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-ui-polish.css') ?>">
<link rel="stylesheet" href="../assets/css/brille-mobile-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-mobile-polish.css') ?>">
<script defer src="../assets/js/messaging.js"></script>
<link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/notification-dropdown-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/notification-dropdown-fix.css') ?>">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<link rel="stylesheet" href="../assets/css/staff-nav-badge.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-nav-badge.css') ?>">
<script defer src="../assets/js/staff-shell.js?v=<?= filemtime(__DIR__ . '/../assets/js/staff-shell.js') ?>"></script>
</head>
<body class="staff-portal-body">
<?php if (!empty($_SESSION['ba_team_welcome_pending'])):
    unset($_SESSION['ba_team_welcome_pending']);
    $welcomeSplashMode = 'login';
    $welcomeSplashLogo = '../assets/img/image.png';
    require __DIR__ . '/welcome_splash.php';
endif; ?>
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
    <a class="nav-item" href="production.php"><span class="icon nav-icon-svg"><?= baIcon('production') ?></span> Production<span class="nav-badge" data-incoming-orders-badge<?= $staffIncomingCount > 0 ? '' : ' hidden' ?> title="New orders waiting for you"><?= $staffIncomingCount > 99 ? '99+' : $staffIncomingCount ?></span></a>
    <a class="nav-item" href="inventory.php"><span class="icon nav-icon-svg"><?= baIcon('inventory') ?></span> Inventory</a>
    <a class="nav-item" href="attendance.php"><span class="icon nav-icon-svg"><?= baIcon('attendance') ?></span> Attendance</a>
    <div class="nav-section-label">Communication</div>
    <a class="nav-item" href="messaging.php"><span class="icon nav-icon-svg"><?= baIcon('messages') ?></span> Messages<?php if ($staffChatCount > 0): ?><span class="nav-badge"><?= $staffChatCount > 99 ? '99+' : $staffChatCount ?></span><?php endif; ?></a>
    <a class="nav-item" href="notifications.php"><span class="icon nav-icon-svg"><?= baIcon('notifications') ?></span> Notifications<?php if ($staffNotifCount > 0): ?><span class="nav-badge"><?= $staffNotifCount > 99 ? '99+' : $staffNotifCount ?></span><?php endif; ?></a>
    <div class="nav-section-label">Account</div>
    <a class="nav-item" href="profile.php"><span class="icon nav-icon-svg"><?= baIcon('profile') ?></span> My Profile</a>
  </nav>
  <div class="sidebar-footer">
    <form method="post" action="logout.php">
      <button type="submit" class="logout-btn"><span class="nav-icon-svg"><?= baIcon('logout') ?></span> Sign Out</button>
    </form>
  </div>
</aside>
<div class="main-content">
<div class="topbar">
  <div class="topbar-left">
    <button type="button" class="nav-toggle" id="baNavToggle" aria-label="Open navigation" aria-controls="baSidebar" aria-expanded="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <div class="topbar-title"><?= $pageTitle ?? 'Dashboard' ?></div>
  </div>
  <div class="topbar-right">
    <?php include __DIR__ . '/messaging_widget.php'; ?>
    <span class="text-sm text-muted"><?= date('D, M j Y') ?></span>
  </div>
</div>
<div class="page-content">

