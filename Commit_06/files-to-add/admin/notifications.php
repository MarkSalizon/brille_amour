<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Notifications';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/messaging.php';

ensureMessagingSchema($conn);
scanDueNotifications($conn, (int)$_SESSION['user_id'], 'admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/notifications-page.css?v=<?= filemtime(__DIR__ . '/../assets/css/notifications-page.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-notifications-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-notifications-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<section class="notifications-page admin-notifications-page" id="notificationsPage"
         data-api="messaging_api.php"
         data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
  <header class="notifications-toolbar">
    <div class="notifications-heading">
      <h1>Notifications</h1>
      <p>Keep track of orders, payments, production, messages, and account activity.</p>
      <span class="notif-unread-count" id="notifUnreadCount" aria-live="polite">Loading unread notifications…</span>
    </div>
    <div class="notification-actions">
      <button type="button" class="btn btn-secondary btn-sm" id="notifMarkAll">Mark all as read</button>
    </div>
  </header>

  <div class="notification-filters" role="search" aria-label="Filter notifications">
    <label class="notif-filter-search" for="notifSearch">
      <span>Search</span>
      <input type="search" id="notifSearch" placeholder="Search notifications" autocomplete="off">
    </label>
    <label class="notif-filter-status" for="notifStatus">
      <span>Status</span>
      <select id="notifStatus"><option value="all">All notifications</option><option value="unread">Unread only</option></select>
    </label>
    <label class="notif-filter-category" for="notifType">
      <span>Category</span>
      <select id="notifType">
        <option value="all">All categories</option>
        <option value="order">Orders</option>
        <option value="production">Production</option>
        <option value="assignment">Assignments</option>
        <option value="deadline">Deadlines</option>
        <option value="inventory">Inventory</option>
        <option value="payment">Payments</option>
        <option value="access">Access requests</option>
        <option value="chat">Messages</option>
      </select>
    </label>
    <details class="notif-more-filters">
      <summary>More filters</summary>
      <label for="notifPriority">
        <span>Priority</span>
        <select id="notifPriority">
          <option value="all">All priorities</option>
          <option value="urgent">Urgent</option>
          <option value="warning">Warning</option>
          <option value="normal">Normal</option>
        </select>
      </label>
    </details>
  </div>

  <section class="notification-list-card" aria-label="Notification history">
    <div class="notification-list-head">
      <strong>Recent activity</strong>
      <span id="notifResultCount" aria-live="polite">Loading…</span>
    </div>
    <div class="notification-history" id="notifHistory" aria-live="polite" aria-relevant="additions text"></div>
  </section>
</section>

<script src="../assets/js/notifications-page.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications-page.js') ?>"></script>

<?php include '../includes/footer.php'; ?>
