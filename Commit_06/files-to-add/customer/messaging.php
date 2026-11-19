<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/messaging.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: login.php?return=messaging.php');
    exit;
}
$realtime = customerSyncRealtimeContext($conn, $customer);
$activePage = 'messages';
$defaultThread = (string)($_SESSION['customer_support_thread_key'] ?? '');
$currentThread = validThreadKey((string)($_GET['thread'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Messages - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/messaging-page.css?v=<?= filemtime(__DIR__ . '/../assets/css/messaging-page.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-messages-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-messages-clean.css') ?>">
  <link rel="stylesheet" href="../assets/css/messaging-app-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/messaging-app-ui.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body class="customer-account-body customer-messages-body customer-portal-body">
  <?php $portalActive = ''; require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-account-content customer-messages-content">

      <section class="account-page-heading compact-heading customer-messages-heading">
        <div><h1>Messages</h1><p>Chat with our team about your orders and payments.</p></div>
      </section>

      <section class="messages-page customer-messages-page ba-chat-page" id="messagesPage"
               data-api="messaging_api.php"
               data-csrf="<?= htmlspecialchars((string)$realtime['csrf']) ?>"
               data-role="customer">
        <div class="messages-shell">
          <aside class="inbox-panel" id="inboxPanel">
            <div class="inbox-head">
              <div>
                <h2>Inbox</h2>
                <p>Your conversations with our team</p>
              </div>
            </div>
            <div class="inbox-search">
              <input type="search" id="messageSearch" placeholder="Search conversations" aria-label="Search conversations" autocomplete="off">
            </div>
            <div class="thread-list" id="pageThreadList"><p class="message-empty">Loading conversations...</p></div>
          </aside>

          <section class="conversation-panel" id="conversationPanel">
            <header class="conversation-head" id="conversationHead">
              <button type="button" class="back-btn" id="messageBack">Inbox</button>
              <div class="chat-avatar">BA</div>
              <div class="conversation-title"><strong>Brille Amour Admin</strong><small>Support conversation</small></div>
              <div class="conversation-actions">
          <button type="button" class="icon-btn conversation-menu-toggle" id="conversationMenuToggle" title="More conversation options" aria-label="More conversation options" aria-haspopup="menu" aria-controls="conversationMenu" aria-expanded="false" disabled><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="5" cy="12" r="1.7" fill="currentColor"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/><circle cx="19" cy="12" r="1.7" fill="currentColor"/></svg></button>
          <div class="conversation-menu" id="conversationMenu" role="menu" aria-label="Conversation options" hidden>
            <button type="button" class="conversation-menu-item" id="markReadBtn" title="Mark as read" role="menuitem">Mark as read</button>
          </div>
        </div>
            </header>
            <div class="message-log" id="pageMessageLog">
              <div class="empty-chat"><strong>Select a conversation</strong><span>Choose a conversation from your inbox to view messages.</span></div>
            </div>
            <form class="message-composer" id="pageMessageComposer" autocomplete="off">
              <textarea id="pageMessageInput" rows="1" placeholder="Write your message…" aria-label="Message to support" maxlength="2000" disabled></textarea>
              <button type="submit" id="pageMessageSend" disabled>Send</button>
            </form>
          </section>
        </div>
      </section>
  </main>
<?php if (!$currentThread && $defaultThread !== ''): ?>
<script>history.replaceState(null, '', 'messaging.php?thread=<?= rawurlencode($defaultThread) ?>');</script>
<?php endif; ?>
<script src="../assets/js/messaging-page.js?v=<?= filemtime(__DIR__ . '/../assets/js/messaging-page.js') ?>"></script>
<link rel="stylesheet" href="../assets/css/search-suggestions.css?v=<?= filemtime(__DIR__ . '/../assets/css/search-suggestions.css') ?>">
<script src="../assets/js/search-suggestions.js?v=<?= filemtime(__DIR__ . '/../assets/js/search-suggestions.js') ?>" defer></script>
</body>
</html>
