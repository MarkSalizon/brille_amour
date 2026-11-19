<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Messages';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/messaging.php';

ensureMessagingSchema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include '../includes/header.php';
?>
<script>document.body.classList.add('messages-static-page', 'admin-messages-clean');</script>
<link rel="stylesheet" href="../assets/css/messaging-page.css?v=<?= filemtime(__DIR__ . '/../assets/css/messaging-page.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-messages-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-messages-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/messaging-app-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/messaging-app-ui.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-messaging-shell-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-messaging-shell-fix.css') ?>">

<section class="messages-page ba-chat-page" id="messagesPage"
         data-api="messaging_api.php"
         data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
         data-role="admin">
  <div class="messages-shell">
    <aside class="inbox-panel" id="inboxPanel">
      <div class="inbox-head">
        <div>
          <h2>Inbox</h2>
          <p id="adminInboxCount">Your conversations</p>
        </div>

      </div>
      <div class="admin-message-groups" role="group" aria-label="Message recipients">
        <button type="button" class="admin-message-group is-selected" data-message-group="customers" aria-pressed="true">Customers</button>
        <button type="button" class="admin-message-group" data-message-group="team" aria-pressed="false">Team</button>
      </div>
      <div class="inbox-search">
        <label class="admin-message-search-label" for="messageSearch">Search customer conversations</label>
        <input type="search" id="messageSearch" placeholder="Search customer conversations" autocomplete="off">
      </div>
      <div class="thread-list" id="pageThreadList">
        <p class="message-empty">Loading conversations...</p>
      </div>
    </aside>

    <main class="conversation-panel" id="conversationPanel">
      <header class="conversation-head" id="conversationHead">
        <button type="button" class="back-btn" id="messageBack">Inbox</button>
        <div class="chat-avatar">BA</div>
        <div class="conversation-title">
          <strong>Select a conversation</strong>
          <small>Choose a conversation from the inbox</small>
        </div>
        <div class="conversation-actions">
          <button type="button" class="icon-btn conversation-menu-toggle" id="conversationMenuToggle" title="More conversation options" aria-label="More conversation options" aria-haspopup="menu" aria-controls="conversationMenu" aria-expanded="false" disabled><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="5" cy="12" r="1.7" fill="currentColor"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/><circle cx="19" cy="12" r="1.7" fill="currentColor"/></svg></button>
          <div class="conversation-menu" id="conversationMenu" role="menu" aria-label="Conversation options" hidden>
            <button type="button" class="conversation-menu-item" id="markReadBtn" title="Mark as read" role="menuitem">Mark as read</button>

            <button type="button" class="conversation-menu-item danger" id="deleteThreadBtn" title="Delete conversation" role="menuitem">Delete</button>
          </div>
        </div>
      </header>

      <div class="message-log" id="pageMessageLog">
        <div class="empty-chat">
          <strong>No conversation selected</strong>
          <span>Choose a customer conversation from the inbox.</span>
        </div>
      </div>

      <form class="message-composer" id="pageMessageComposer" autocomplete="off">
        <textarea id="pageMessageInput" rows="1" placeholder="Write your reply…" aria-label="Message reply" maxlength="2000" disabled></textarea>
        <button type="submit" id="pageMessageSend" disabled>Send</button>
      </form>
    </main>
  </div>
</section>

<script src="../assets/js/messaging-page.js?v=<?= filemtime(__DIR__ . '/../assets/js/messaging-page.js') ?>"></script>

<?php include '../includes/footer.php'; ?>
