<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
if (!isset($conn)) {
  require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/messaging.php';
if (file_exists(__DIR__ . '/staff_roles.php')) {
  require_once __DIR__ . '/staff_roles.php';
}

$sidebarUser = currentUser();
$sidebarRole = ($sidebarUser['role'] ?? '') === 'admin' ? 'admin' : 'staff';
$sidebarIsAdmin = $sidebarRole === 'admin';
$sidebarName = $sidebarUser['full_name'] ?: $sidebarUser['username'] ?: 'User';
$sidebarInitials = strtoupper(substr($sidebarName, 0, 1));
$sidebarStaffRole = function_exists('isValidStaffRole') && isValidStaffRole($sidebarUser['staff_role'] ?? null)
  ? staffRoleLabel($sidebarUser['staff_role'])
  : ($sidebarIsAdmin ? 'Administrator' : 'Production Staff');

ensureMessagingSchema($conn);
$sidebarUserId = (int)$sidebarUser['id'];
$orderScope = $sidebarIsAdmin ? '' : "AND (assigned_to={$sidebarUserId} OR created_by={$sidebarUserId})";
$badgePendingOrders = (int)($conn->query("SELECT COUNT(*) FROM orders WHERE status='pending' AND status NOT IN ('archived') {$orderScope}")->fetch_row()[0] ?? 0);
$badgePriorityOrders = (int)($conn->query("SELECT COUNT(*) FROM orders WHERE priority IN ('urgent','high') AND status NOT IN ('completed','cancelled','archived') {$orderScope}")->fetch_row()[0] ?? 0);
$badgeProduction = (int)($conn->query("SELECT COUNT(*) FROM orders WHERE status='in_production' {$orderScope}")->fetch_row()[0] ?? 0);
$badgeLowStock = (int)($conn->query("SELECT COUNT(*) FROM inventory WHERE quantity<=reorder_level AND status='active'")->fetch_row()[0] ?? 0);
$badgeNotifications = unreadNotificationCount($conn, $sidebarUserId);
$badgeMessages = unreadChatTotal($conn, $sidebarUserId);

$sidebarCurrent = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$navigation = [
  'Overview' => [
    ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'dashboard', 'roles' => ['admin']],
  ],
  'Operations' => [
    ['label' => 'Payments', 'url' => 'payments.php', 'icon' => 'orders', 'roles' => ['admin']],
    ['label' => 'Orders', 'url' => 'orders.php', 'icon' => 'orders', 'roles' => ['admin'], 'badge' => $badgePriorityOrders ?: $badgePendingOrders],
    ['label' => 'Production Board', 'url' => 'production.php', 'icon' => 'production', 'roles' => ['admin', 'staff'], 'badge' => $badgeProduction],
    ['label' => 'Inventory', 'url' => 'inventory.php', 'icon' => 'inventory', 'roles' => ['admin', 'staff'], 'badge' => $badgeLowStock],
    ['label' => 'Customers', 'url' => 'customers.php', 'icon' => 'customers', 'roles' => ['admin']],
  ],
  'Communication' => [
    ['label' => 'Messages', 'url' => 'messaging.php', 'icon' => 'messages', 'roles' => ['staff'], 'badge' => $badgeMessages],
    ['label' => 'Notifications', 'url' => 'notifications.php', 'icon' => 'notifications', 'roles' => ['admin', 'staff'], 'badge' => $badgeNotifications],
  ],
  'Management' => [
    ['label' => 'Products', 'url' => 'products.php', 'icon' => 'products', 'roles' => ['admin']],
    ['label' => 'Users', 'url' => 'users.php', 'icon' => 'users', 'roles' => ['admin']],
  ],
  'Analytics' => [
    ['label' => 'Reports', 'url' => 'reports.php', 'icon' => 'reports', 'roles' => ['admin']],
    ['label' => 'Smart Operations', 'url' => 'innovation.php', 'icon' => 'reports', 'roles' => ['admin']],
  ],
  'Records' => [
    ['label' => 'Archive', 'url' => 'archive.php', 'icon' => 'archive', 'roles' => ['admin']],
  ],
  'Account' => [
    ['label' => 'Profile', 'url' => 'profile.php', 'icon' => 'profile', 'roles' => ['staff']],
  ],
];

if (!function_exists('sidebarIcon')) {
  function sidebarIcon(string $name): string
  {
    $paths = [
      'dashboard' => '<path d="M4 5h6v6H4z"/><path d="M14 5h6v4h-6z"/><path d="M14 13h6v6h-6z"/><path d="M4 15h6v4H4z"/>',
      'orders' => '<path d="M7 3h10l3 3v15H4V3z"/><path d="M8 9h8"/><path d="M8 13h8"/><path d="M8 17h5"/>',
      'production' => '<path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5z"/><path d="M19.4 15a8 8 0 0 0 .1-2l2-1.5-2-3.5-2.4 1a7 7 0 0 0-1.7-1L15 5h-4l-.4 3a7 7 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a8 8 0 0 0 .1 2l-2 1.5 2 3.5 2.4-1a7 7 0 0 0 1.7 1l.4 3h4l.4-3a7 7 0 0 0 1.7-1l2.4 1 2-3.5z"/>',
      'inventory' => '<path d="M21 8 12 3 3 8l9 5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
      'customers' => '<path d="M16 11a4 4 0 1 0-8 0"/><path d="M4 21a8 8 0 0 1 16 0"/><path d="M19 8a3 3 0 0 1 2 5"/>',
      'messages' => '<path d="M4 5h16v11H7l-3 3z"/><path d="M8 9h8"/><path d="M8 13h5"/>',
      'notifications' => '<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
      'products' => '<path d="M20 12v8H4v-8"/><path d="M2 7h20v5H2z"/><path d="M12 7v13"/><path d="M12 7c-1.5 0-4-.5-4-2.2C8 3.8 8.8 3 9.8 3 11.5 3 12 7 12 7z"/><path d="M12 7s.5-4 2.2-4c1 0 1.8.8 1.8 1.8C16 6.5 13.5 7 12 7z"/>',
      'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.8"/><path d="M17 3.3a4 4 0 0 1 0 7.4"/>',
      'reports' => '<path d="M4 19V5"/><path d="M4 19h17"/><path d="M8 16v-5"/><path d="M13 16V8"/><path d="M18 16v-3"/>',
      'archive' => '<path d="M3 5h18v4H3z"/><path d="M5 9v10h14V9"/><path d="M10 13h4"/>',
      'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    ];

    return '<svg class="nav-svg" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['dashboard']) . '</svg>';
  }
}
?>
<aside class="sidebar" id="sidebar" aria-label="Sidebar navigation">
  <div class="sidebar-brand">
    <a class="brand-lockup" href="<?= $sidebarIsAdmin ? 'dashboard.php' : 'production.php' ?>" aria-label="Go to main page">
      <span class="brand-symbol">BA</span>
      <span class="brand-copy">
        <span class="logo-text">Brille Amour</span>
        <span class="logo-sub">Production Management</span>
      </span>
    </a>
    <button class="sidebar-collapse" id="sidebarCollapseToggle" type="button" aria-label="Collapse sidebar" aria-controls="sidebar" aria-expanded="true">
      <span aria-hidden="true"></span>
    </button>
    <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Close navigation" aria-hidden="true"></button>
  </div>

  <nav class="sidebar-nav" aria-label="Primary navigation">
    <?php foreach ($navigation as $group => $items): ?>
      <?php
      $visibleItems = array_filter($items, static function (array $item) use ($sidebarRole): bool {
        return in_array($sidebarRole, $item['roles'], true) && file_exists(dirname(__DIR__) . '/' . $sidebarRole . '/' . $item['url']);
      });
      ?>
      <?php if ($visibleItems): ?>
        <div class="nav-section-label"><?= htmlspecialchars($group) ?></div>
        <?php foreach ($visibleItems as $item): ?>
          <?php $isActive = $sidebarCurrent === $item['url']; ?>
          <a class="nav-item <?= $isActive ? 'active' : '' ?>"
            href="<?= htmlspecialchars($item['url']) ?>"
            data-label="<?= htmlspecialchars($item['label']) ?>"
            title="<?= htmlspecialchars($item['label']) ?>"
            <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="icon"><?= sidebarIcon($item['icon']) ?></span>
            <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?>
              <span class="nav-badge"><?= (int)$item['badge'] > 99 ? '99+' : (int)$item['badge'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-account">
      <span class="sidebar-avatar"><?= htmlspecialchars($sidebarInitials) ?></span>
      <span class="sidebar-account-copy">
        <strong><?= htmlspecialchars($sidebarName) ?></strong>
        <small><?= htmlspecialchars($sidebarStaffRole) ?></small>
      </span>
    </div>
    <?php if (!$sidebarIsAdmin): ?>
      <div class="sidebar-account-actions">
        <a class="account-action" href="profile.php">Profile</a>
        <form method="post" action="logout.php">
          <button type="submit" class="logout-btn">Logout</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</aside>
