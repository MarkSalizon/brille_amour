<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/messaging.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: login.php?return=notifications.php');
    exit;
}
$realtime = customerSyncRealtimeContext($conn, $customer);
$activePage = 'notifications';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Notifications - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/notifications-page.css?v=<?= filemtime(__DIR__ . '/../assets/css/notifications-page.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-notifications-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-notifications-ui.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body class="customer-account-body customer-portal-body customer-notifications-body">
  <?php $portalActive = ''; require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-account-content">

      <section class="notifications-page customer-notifications-page" id="notificationsPage"
               data-api="messaging_api.php"
               data-csrf="<?= htmlspecialchars((string)$realtime['csrf']) ?>">
        <div class="notifications-toolbar">
          <div class="notifications-heading">
            <span class="notifications-eyebrow">Your account</span>
            <h1>Notifications</h1>
            <p>Order updates, payment confirmations, and messages in one place.</p>
          </div>
          <div class="notification-actions">
            <button type="button" class="btn btn-secondary btn-sm" id="notifMarkAll">Mark all as read</button>
          </div>
        </div>

        <div class="notification-filters" role="search" aria-label="Filter notifications">
          <label class="notif-search-wrap">
            <span class="notif-filter-label">Search</span>
            <input type="search" id="notifSearch" placeholder="Search your notifications" autocomplete="off">
          </label>
          <label class="notif-filter-field">
            <span class="notif-filter-label">Show</span>
            <select id="notifStatus"><option value="all">All notifications</option><option value="unread">Unread only</option></select>
          </label>
          <label class="notif-filter-field">
            <span class="notif-filter-label">Category</span>
            <select id="notifType">
              <option value="all">All categories</option>
              <option value="production">Order progress</option>
              <option value="chat">Messages</option>
              <option value="payment">Payments</option>
              <option value="order">Orders</option>
            </select>
          </label>
          <details class="notif-extra-filters">
            <summary>More filters</summary>
            <label class="notif-filter-field" for="notifPriority">
              <span class="notif-filter-label">Priority</span>
              <select id="notifPriority">
                <option value="all">All priorities</option>
                <option value="urgent">Urgent</option>
                <option value="warning">Warning</option>
                <option value="normal">Normal</option>
              </select>
            </label>
          </details>
        </div>

        <div class="notification-list-card">
          <div class="notification-list-head"><strong>Recent activity</strong><span id="notifResultCount" aria-live="polite">Loading...</span></div>
          <div class="notification-history" id="notifHistory" aria-live="polite" aria-relevant="additions text"></div>
        </div>
      </section>
  </main>
<script src="../assets/js/notifications-page.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications-page.js') ?>"></script>
<link rel="stylesheet" href="../assets/css/search-suggestions.css?v=<?= filemtime(__DIR__ . '/../assets/css/search-suggestions.css') ?>">
<script src="../assets/js/search-suggestions.js?v=<?= filemtime(__DIR__ . '/../assets/js/search-suggestions.js') ?>" defer></script>
</body>
</html>
