<?php
/** Floating public Brille Bot widget. */
?>
<section class="ba-bot" id="baBot" data-brille-bot data-api="chatbot_api.php" aria-label="Brille Bot assistant">
  <button type="button" class="ba-bot-launcher" id="baBotLauncher" aria-label="Ask Brille Bot" aria-expanded="false" aria-controls="baBotPanel">
    <span class="ba-bot-launcher-icon" aria-hidden="true"><img src="../assets/img/image.png" alt=""></span>
    <span>Ask Brille Bot</span>
    <em class="ba-bot-unread" id="baBotUnread" hidden>0</em>
  </button>

  <div class="ba-bot-panel is-hidden" id="baBotPanel" hidden aria-hidden="true">
    <header class="ba-bot-header">
      <div>
        <strong>Brille Bot</strong>
        <small>Order, pickup, payment, and product help</small>
        <small class="ba-bot-live-status" id="baBotLiveStatus">Admin support</small>
      </div>
      <button type="button" class="ba-bot-close" id="baBotClose" aria-label="Close chatbot">&times;</button>
    </header>

    <div class="ba-bot-quick" aria-label="Quick chatbot actions">
      <button type="button" data-ba-bot-mode="order_status">Order Status</button>
      <button type="button" data-ba-bot-mode="pickup">Pickup Date</button>
      <button type="button" data-ba-bot-mode="payment">Payment</button>
      <button type="button" data-ba-bot-mode="products">Products</button>
      <button type="button" class="ba-bot-messages" id="baBotMessages" hidden>Message staff <span id="baBotMessagesBadge" hidden></span></button>
    </div>

    <div class="ba-bot-smart-form" id="baBotSmartForm" hidden></div>

    <div class="ba-bot-log" id="baBotLog" aria-live="polite">
      <article class="ba-bot-message bot">Hi! I can track your order by order number or customer name, check pickup availability, payment methods, and products.</article>
    </div>

    <form class="ba-bot-composer" id="baBotComposer" autocomplete="off">
      <textarea id="baBotInput" rows="1" placeholder="Type your question..." maxlength="700"></textarea>
      <button type="submit">Send</button>
    </form>
  </div>
</section>
