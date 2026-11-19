<?php
require_once __DIR__ . '/../includes/auth_redirect.php';
startPortalSession('admin');
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: login.php');
  exit;
}
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/innovation.php';
require_once '../includes/payments.php';
ensureCheckoutPaymentSchema($conn);
ensureInnovationSchema($conn);
require_once '../includes/paymongo.php';
ensurePaymongoSchema($conn);
$_SESSION['payment_csrf'] ??= bin2hex(random_bytes(32));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($_SESSION['payment_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
      throw new RuntimeException('Session expired. Refresh and try again.');
    }
    if (($_POST['decision'] ?? '') === 'sync_paymongo') {
      $gateway = paymongoFind($conn, (int)($_POST['id'] ?? 0));
      $sessionId = $gateway['session_id'] ?? '';
      if ($sessionId === '') {
        $sessionId = trim((string)($_POST['session_id'] ?? ''));
      }
      if (!$gateway || !preg_match('/^cs_[A-Za-z0-9]+$/', $sessionId) || (bool)$gateway['livemode'] !== paymongoLive()) {
        throw new RuntimeException('Enter the matching checkout session ID from the PayMongo dashboard and use the matching API mode.');
      }
      $remote = paymongoRequest('GET', 'checkout_sessions/' . rawurlencode($sessionId));
      paymongoPaidPayment($remote, $gateway);
      $url = (string)($remote['attributes']['checkout_url'] ?? '');
      if (!paymongoCheckoutUrl($url)) {
        throw new RuntimeException('Invalid checkout URL.');
      }
      $stmt = $conn->prepare("UPDATE paymongo_checkouts SET session_id=?,checkout_url=?,state=IF(state IN ('new','creating'),'open',state) WHERE payment_id=? AND (session_id IS NULL OR session_id=?)");
      $stmt->bind_param('ssis', $sessionId, $url, $gateway['payment_id'], $sessionId);
      $stmt->execute();
      paymongoSettle($conn, (int)$gateway['payment_id'], $remote);
      header('Location: payments.php?payment=' . (int)$gateway['payment_id'] . '&saved=1');
      exit;
    }
    $paymentPostId = (int)($_POST['id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    reviewCheckoutPayment($conn, $paymentPostId, $decision, substr(trim((string)($_POST['note'] ?? '')), 0, 500), (int)$_SESSION['user_id']);
    // A successful verification returns to the payment list with the modal closed.
    // Keep the selected payment on other actions so the admin can continue reviewing it.
    $returnUrl = $decision === 'verified' ? 'payments.php?saved=verified' : 'payments.php?payment=' . $paymentPostId . '&saved=1';
    header('Location: ' . $returnUrl);
    exit;
  } catch (RuntimeException $e) {
    $error = $e->getMessage();
  }
}

$payments = $conn->query("SELECT p.*,g.state AS gateway_state,g.livemode,g.session_id,g.paid_payment_id FROM checkout_payments p LEFT JOIN paymongo_checkouts g ON g.payment_id=p.id ORDER BY p.status='pending' DESC,p.id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$gcashSettings = getSettings($conn);

function pvOrders(mysqli $conn, int $paymentId): array {
  $stmt = $conn->prepare('SELECT o.order_number,o.customer_name,o.id,o.status,o.fulfillment_method,o.delivery_fee,o.delivery_address,o.due_date,o.pickup_time,po.amount FROM checkout_payment_orders po JOIN orders o ON o.id=po.order_id WHERE po.payment_id=? ORDER BY o.id');
  $stmt->bind_param('i', $paymentId);
  $stmt->execute();
  return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$gatewayPayments = [];
$manualPayments = [];
$awaitingCount = 0;
$verifiedToday = 0;
$pendingReview = 0;
$creditedToday = 0.0;
$today = date('Y-m-d');

foreach ($payments as $payment) {
  if ($payment['gateway_state'] !== null) {
    $gatewayPayments[] = $payment;
    if ($payment['status'] === 'pending' && in_array((string)$payment['gateway_state'], ['paid','test_paid'], true) && !empty($payment['paid_payment_id'])) {
      $awaitingCount++;
    }
    if ($payment['status'] === 'pending' && (string)$payment['gateway_state'] === 'review') {
      $pendingReview++;
    }
  } else {
    $manualPayments[] = $payment;
    if ($payment['status'] === 'pending') $pendingReview++;
  }
  if ($payment['status'] === 'verified' && !empty($payment['reviewed_at']) && substr((string)$payment['reviewed_at'], 0, 10) === $today) {
    $verifiedToday++;
    $creditedToday += (float)$payment['amount'];
  }
}

$selectedId = (int)($_GET['payment'] ?? ($_POST['id'] ?? 0));
$selected = null;
foreach ($gatewayPayments as $payment) {
  if ((int)$payment['id'] === $selectedId) { $selected = $payment; break; }
}
if (!$selected) {
  foreach ($gatewayPayments as $payment) {
    if ($payment['status'] === 'pending' && in_array((string)$payment['gateway_state'], ['paid','test_paid'], true) && !empty($payment['paid_payment_id'])) { $selected = $payment; break; }
  }
}
if (!$selected && $gatewayPayments) $selected = $gatewayPayments[0];
$selectedOrders = $selected ? pvOrders($conn, (int)$selected['id']) : [];

$pageTitle = 'Payments';
require_once '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/payment-verification.css?v=<?= filemtime(__DIR__ . '/../assets/css/payment-verification.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-payments-professional.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-payments-professional.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">

<div class="pv-page">
  <div class="pv-hero">
    <div>
      <h2>Payments</h2>
      <p>Review transactions, verify customer payments, and track their status in one place.</p>
    </div>
  </div>

  <?php if ($error): ?><div class="pv-alert danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><div class="pv-alert success" role="status"><?= ($_GET['saved'] ?? '') === 'verified' ? 'Payment verified and credited successfully.' : 'Payment review saved successfully.' ?></div><?php endif; ?>

  <section class="pv-stats" aria-label="Payment verification summary">
    <div class="pv-stat"><div class="pv-stat-copy"><span>Ready to verify</span><strong><?= $awaitingCount ?></strong><small>Gateway-confirmed payments</small></div></div>
    <div class="pv-stat"><div class="pv-stat-copy"><span>Verified today</span><strong><?= $verifiedToday ?></strong><small>Approved transactions</small></div></div>
    <div class="pv-stat"><div class="pv-stat-copy"><span>Needs review</span><strong><?= $pendingReview ?></strong><small>Gateway review / manual receipts</small></div></div>
    <div class="pv-stat"><div class="pv-stat-copy"><span>Credited today</span><strong><?= formatMoney($creditedToday) ?></strong><small>Verified payments</small></div></div>
  </section>

  <section class="pv-workspace">
    <div class="pv-panel pv-payments-panel">
      <div class="pv-panel-head">
        <div><h3>Payment transactions</h3><small>PayMongo transactions · most recent records first</small></div>
        <span class="pv-count" aria-label="<?= count($gatewayPayments) ?> PayMongo transactions"><?= count($gatewayPayments) ?></span>
      </div>
      <div class="pv-toolbar" role="search" aria-label="Find payment transactions">
        <label class="pv-search-field" for="pvSearch">
          <input type="search" id="pvSearch" placeholder="Search order, customer, reference…" autocomplete="off">
        </label>
        <label class="pv-filter-field" for="pvFilter">Status
          <select id="pvFilter">
            <option value="all">All statuses</option>
            <option value="ready">Ready to verify</option>
            <option value="waiting">Awaiting payment</option>
            <option value="review">Needs review</option>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
          </select>
        </label>
        <button type="button" class="pv-clear-filter" id="pvClearFilters" hidden>Clear filters</button>
      </div>
      <div class="pv-result-bar"><span id="pvResults" aria-live="polite">Showing <?= count($gatewayPayments) ?> transactions</span><small>Showing up to the latest 100 payments</small></div>
      <div class="pv-list" id="pvTransactionList">
        <?php if (!$gatewayPayments): ?>
          <div class="pv-empty">No PayMongo payment records yet.</div>
        <?php else: ?>
          <div class="pv-table-head" aria-hidden="true">
            <span>Order / Customer</span><span>Amount</span><span>Reference / Method</span><span>Status</span><span></span>
          </div>
        <?php endif; ?>

        <?php foreach ($gatewayPayments as $payment):
          $orders = pvOrders($conn, (int)$payment['id']);
          $firstOrder = $orders[0] ?? null;
          $fulfillmentMethod = strtolower(trim((string)($firstOrder['fulfillment_method'] ?? 'pickup')));
          $fulfillmentLabel = $fulfillmentMethod === 'delivery' ? 'Delivery' : 'Pickup';
          $paymentKindLabel = $fulfillmentMethod === 'delivery' ? 'Full payment' : 'Downpayment';
          $confirmed = in_array((string)$payment['gateway_state'], ['paid','test_paid'], true) && !empty($payment['paid_payment_id']);
          $needsGatewayReview = (string)$payment['gateway_state'] === 'review';
          $isVerified = (string)$payment['status'] === 'verified';
          $isRejected = (string)$payment['status'] === 'rejected';
          $displayStatus = $isVerified ? 'verified' : ($isRejected ? 'rejected' : ($confirmed ? 'ready' : ($needsGatewayReview ? 'review' : 'waiting')));
          $badgeClass = $isVerified ? 'verified' : ($isRejected ? 'rejected' : ($confirmed ? 'confirmed' : ($needsGatewayReview ? 'review' : 'pending')));
          $badgeText = $isVerified ? 'Verified' : ($isRejected ? 'Rejected' : ($confirmed ? 'Ready to Verify' : ($needsGatewayReview ? 'Needs Review' : 'Awaiting Payment')));
          $modalId = 'pv-payment-' . (int)$payment['id'];
        ?>
          <article class="pv-row" data-pv-row data-pv-status="<?= $displayStatus ?>" data-pv-search="<?= htmlspecialchars(mb_strtolower(implode(' ', array_merge([$payment['reference_number'], paymentMethodLabel($payment), (string)$payment['status'], (string)$payment['gateway_state']], array_map(static fn(array $order): string => $order['order_number'] . ' ' . $order['customer_name'], $orders))), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>">
            <div class="pv-customer"><strong><?= htmlspecialchars($firstOrder['order_number'] ?? $payment['reference_number']) ?></strong><small><?= htmlspecialchars($firstOrder['customer_name'] ?? 'Customer checkout') ?></small><small><strong><?= htmlspecialchars($fulfillmentLabel) ?></strong><?= $fulfillmentMethod === 'delivery' && !empty($firstOrder['delivery_address']) ? ' · ' . htmlspecialchars((string)$firstOrder['delivery_address']) : '' ?></small><small class="pv-submitted"><?= htmlspecialchars(appDateTime($payment['created_at'])) ?><?= count($orders) > 1 ? ' · ' . count($orders) . ' linked orders' : '' ?></small></div>
            <div class="pv-amount"><strong><?= formatMoney($payment['amount']) ?></strong><small><?= htmlspecialchars($paymentKindLabel) ?></small></div>
            <div class="pv-ref"><strong><?= htmlspecialchars($payment['reference_number']) ?></strong><small><?= htmlspecialchars(paymentMethodLabel($payment)) ?> · <?= !empty($payment['livemode']) ? 'Live' : 'Test' ?></small></div>
            <span class="pv-badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeText) ?></span>
            <button type="button" class="pv-review-link" data-pv-open="<?= $modalId ?>"><?= $isVerified || $isRejected ? 'View details' : 'Review payment' ?></button>
          </article>

          <div class="pv-modal" id="<?= $modalId ?>" hidden>
            <div class="pv-modal-backdrop" data-pv-close></div>
            <section class="pv-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="<?= $modalId ?>-title">
              <div class="pv-modal-head">
                <div>
                  <span class="pv-modal-kicker">Payment Review</span>
                  <h3 id="<?= $modalId ?>-title"><?= htmlspecialchars($firstOrder['order_number'] ?? $payment['reference_number']) ?></h3>
                </div>
                <div class="pv-modal-head-actions">
                  <button type="button" class="pv-modal-close" data-pv-close aria-label="Close payment review"><span aria-hidden="true"></span></button>
                </div>
              </div>

              <div class="pv-modal-body">
                <div class="pv-detail-top">
                  <div>
                    <div class="pv-order-number"><?= htmlspecialchars($firstOrder['order_number'] ?? $payment['reference_number']) ?></div>
                    <div class="pv-success-line">
                      <strong><?= $isVerified ? htmlspecialchars($paymentKindLabel . ' verified') : ($isRejected ? 'Payment rejected' : ($confirmed ? 'Payment confirmed by gateway' : 'Awaiting gateway confirmation')) ?></strong>
                    </div>
                    <span class="pv-state-pill <?= $isVerified ? 'verified' : '' ?>"><?= $isVerified ? 'Admin verified' : ($isRejected ? 'Rejected' : ($confirmed ? 'Awaiting admin verification' : 'Awaiting payment confirmation')) ?></span>
                  </div>
                  <span class="pv-badge <?= $badgeClass ?>"><?= htmlspecialchars(strtoupper($badgeText)) ?></span>
                </div>

                <div class="pv-detail-grid">
                  <div class="pv-detail-row"><span>Customer</span><strong><?= htmlspecialchars($firstOrder['customer_name'] ?? 'Not available') ?></strong></div>
                  <div class="pv-detail-row"><span>Order Method</span><strong><?= htmlspecialchars($fulfillmentLabel) ?></strong></div>
                  <?php if ($fulfillmentMethod === 'delivery'): ?>
                    <?php $historicalDeliveryFee = array_sum(array_map(static fn($row) => (float)($row['delivery_fee'] ?? 0), $orders)); ?>
                    <?php if ($historicalDeliveryFee > 0): ?><div class="pv-detail-row"><span>Previously Charged Delivery Fee</span><strong><?= formatMoney($historicalDeliveryFee) ?></strong></div><?php endif; ?>
                    <div class="pv-detail-row"><span>Delivery Address</span><strong><?= htmlspecialchars(trim((string)($firstOrder['delivery_address'] ?? '')) !== '' ? (string)$firstOrder['delivery_address'] : 'Not provided') ?></strong></div>
                  <?php else: ?>
                    <div class="pv-detail-row"><span>Pickup Location</span><strong>Brille Amour Prints &amp; Crafts</strong></div>
                  <?php endif; ?>
                  <div class="pv-detail-row"><span><?= $fulfillmentMethod === 'delivery' ? 'Delivery Schedule' : 'Pickup Schedule' ?></span><strong><?= htmlspecialchars(trim((string)($firstOrder['due_date'] ?? '')) . (trim((string)($firstOrder['pickup_time'] ?? '')) !== '' ? ' · ' . trim((string)$firstOrder['pickup_time']) : '')) ?></strong></div>
                  <div class="pv-detail-row"><span>Reference No.</span><strong><?= htmlspecialchars($payment['reference_number']) ?></strong></div>
                  <div class="pv-detail-row"><span><?= htmlspecialchars($paymentKindLabel) ?></span><strong><?= formatMoney($payment['amount']) ?></strong></div>
                  <div class="pv-detail-row"><span>Payment Method</span><strong><?= htmlspecialchars(paymentMethodLabel($payment)) ?> <?= !empty($payment['livemode']) ? 'Live' : 'Test' ?></strong></div>
                  <div class="pv-detail-row"><span>PayMongo ID</span><strong><?= htmlspecialchars($payment['paid_payment_id'] ?? 'Not confirmed') ?></strong></div>
                  <div class="pv-detail-row"><span>Submitted</span><strong><?= htmlspecialchars(appDateTime($payment['created_at'])) ?></strong></div>
                  <div class="pv-detail-row"><span>Gateway state</span><strong><?= htmlspecialchars((string)$payment['gateway_state']) ?></strong></div>
                </div>
                <?php if ($orders): ?>
                  <div class="pv-linked-orders">
                    <h4>Linked orders <span><?= count($orders) ?></span></h4>
                    <?php foreach ($orders as $linkedOrder): ?>
                      <a href="orders.php?view=<?= (int)$linkedOrder['id'] ?>"><span><?= htmlspecialchars($linkedOrder['order_number']) ?> <small><?= htmlspecialchars($linkedOrder['customer_name']) ?> · <?= strtolower(trim((string)($linkedOrder['fulfillment_method'] ?? 'pickup'))) === 'delivery' ? 'Delivery' : 'Pickup' ?></small></span><strong><?= formatMoney($linkedOrder['amount']) ?></strong></a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if ($confirmed && $payment['status'] !== 'verified'): ?>
                  <form method="post" class="pv-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['payment_csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int)$payment['id'] ?>">
                    <button class="pv-primary" name="decision" value="verified"><?= $fulfillmentMethod === 'delivery' ? 'Verify &amp; Credit Full Payment' : 'Verify &amp; Credit Downpayment' ?></button>
                  </form>
                <?php elseif ($payment['status'] === 'verified'): ?>
                  <div class="pv-verified-box"><strong>Verification complete.</strong><br>The <?= $fulfillmentMethod === 'delivery' ? 'full payment' : 'downpayment' ?> has been credited. Verified orders enter Production Waiting immediately. Waiting has no limit; the active-work limit applies only after staff accepts an order.</div>
                <?php elseif ($needsGatewayReview): ?>
                  <div class="pv-admin-note"><strong>Gateway review required.</strong> Reconcile the PayMongo checkout before admin verification.</div>
                <?php else: ?>
                  <div class="pv-admin-note">PayMongo has not confirmed a paid transaction yet. Refresh the gateway status before verification.</div>
                <?php endif; ?>

                <?php if ($payment['status'] !== 'verified'): ?>
                  <form method="post" class="pv-form pv-sync-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['payment_csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int)$payment['id'] ?>">
                    <?php if (!$payment['session_id']): ?><label>Checkout session ID<input type="text" name="session_id" pattern="cs_[A-Za-z0-9]+" required placeholder="cs_..."></label><?php endif; ?>
                    <button class="pv-secondary" name="decision" value="sync_paymongo">Refresh PayMongo Status</button>
                  </form>
                <?php endif; ?>

                <div class="pv-detail-actions">
                  <?php if ($firstOrder): ?><a href="orders.php?view=<?= (int)$firstOrder['id'] ?>">View Order</a><?php endif; ?>
                  <a href="production.php">Production</a>
                  <a href="orders.php">All Orders</a>
                </div>
              </div>
            </section>
          </div>
        <?php endforeach; ?>
        <?php if ($gatewayPayments): ?><p class="pv-no-matches" id="pvNoMatches" hidden>No matching payments. Try a different search or status.</p><?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($manualPayments): ?>
  <section class="pv-manual">
    <div class="pv-section-title"><div><h3>Legacy Manual Payments</h3><p>Manual receipt verification is retained only for older customer submissions.</p></div><span class="pv-count"><?= count($manualPayments) ?></span></div>
    <?php foreach ($manualPayments as $payment):
      $orders = pvOrders($conn, (int)$payment['id']);
      $manualFirstOrder = $orders[0] ?? null;
      $manualFulfillment = strtolower(trim((string)($manualFirstOrder['fulfillment_method'] ?? 'pickup')));
      $manualPaymentLabel = $manualFulfillment === 'delivery' ? 'Expected full payment' : 'Expected downpayment';
    ?>
      <article class="pv-manual-card" id="payment-<?= (int)$payment['id'] ?>">
        <div class="pv-manual-head"><div><span class="pv-badge <?= $payment['status'] === 'verified' ? 'verified' : ($payment['status'] === 'rejected' ? 'review' : 'pending') ?>"><?= htmlspecialchars(strtoupper($payment['status'])) ?></span><h3 style="margin:7px 0 0;font-size:16px"><?= htmlspecialchars(paymentStatusLabel($payment['status'])) ?></h3></div><small style="color:#8f7d76">Submitted <?= htmlspecialchars(appDateTime($payment['created_at'])) ?></small></div>
        <div class="pv-manual-meta"><div><span>Reference</span><strong><?= htmlspecialchars($payment['reference_number']) ?></strong></div><div><span>Payment method</span><strong><?= htmlspecialchars(paymentMethodLabel($payment)) ?></strong></div><div><span><?= htmlspecialchars($manualPaymentLabel) ?></span><strong><?= formatMoney($payment['amount']) ?></strong></div><div><span>Linked orders</span><strong><?= count($orders) ?></strong></div></div>
        <div class="pv-manual-grid">
          <div>
            <div class="pv-checklist"><strong>Verification checklist</strong><ol><li>Receipt matches a successful transaction in the shop payment history.</li><li>Amount received is exactly <strong><?= formatMoney($payment['amount']) ?></strong>.</li><li>Recipient matches the configured shop account<?= ($gcashSettings['gcash_name'] ?? '') !== '' ? ': <strong>' . htmlspecialchars($gcashSettings['gcash_name']) . '</strong>' : '' ?>.</li></ol></div>
            <?php if ($payment['review_note'] !== ''): ?><p style="font-size:11px"><strong>Review note:</strong> <?= htmlspecialchars($payment['review_note']) ?></p><?php endif; ?>
            <?php if ($payment['status'] !== 'verified'): ?>
              <form method="post" class="pv-form" style="margin-top:12px">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['payment_csrf']) ?>"><input type="hidden" name="id" value="<?= (int)$payment['id'] ?>">
                <label>Review note<textarea name="note" maxlength="500" rows="3" placeholder="Optional verification note, or explain why the payment is rejected"></textarea></label>
                <label class="pv-confirm"><input type="checkbox" name="confirmed" value="1"><span>I personally checked the shop transaction history and confirmed the exact amount <?= formatMoney($payment['amount']) ?>.</span></label>
                <div class="pv-inline-buttons"><button class="verify" name="decision" value="verified">Verify &amp; Start Production</button><button class="reject" name="decision" value="rejected">Reject Payment</button></div>
              </form>
            <?php endif; ?>
          </div>
          <div><a href="payment-proof.php?id=<?= (int)$payment['id'] ?>" target="_blank" rel="noopener" style="font-size:11px">Open full-size receipt</a><img src="payment-proof.php?id=<?= (int)$payment['id'] ?>" alt="Customer payment receipt" loading="lazy" class="pv-proof" style="margin-top:8px"></div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</div>

<script>
(function () {
  var activeModal = null;
  var lastTrigger = null;
  var search = document.getElementById('pvSearch');
  var filter = document.getElementById('pvFilter');
  var clear = document.getElementById('pvClearFilters');
  var results = document.getElementById('pvResults');
  var noMatches = document.getElementById('pvNoMatches');
  var rows = Array.from(document.querySelectorAll('[data-pv-row]'));

  function applyPaymentFilters() {
    var term = (search?.value || '').trim().toLocaleLowerCase();
    var status = filter?.value || 'all';
    var visible = 0;
    rows.forEach(function (row) {
      var matches = (status === 'all' || row.dataset.pvStatus === status) && (!term || (row.dataset.pvSearch || '').includes(term));
      row.hidden = !matches;
      if (matches) visible++;
    });
    if (results) results.textContent = 'Showing ' + visible + ' of ' + rows.length + ' transactions';
    if (noMatches) noMatches.hidden = visible !== 0;
    if (clear) clear.hidden = !term && status === 'all';
  }
  search?.addEventListener('input', applyPaymentFilters);
  filter?.addEventListener('change', applyPaymentFilters);
  clear?.addEventListener('click', function () {
    if (search) search.value = '';
    if (filter) filter.value = 'all';
    applyPaymentFilters();
    search?.focus();
  });

  function openPaymentModal(id, trigger) {
    var modal = document.getElementById(id);
    if (!modal) return;
    if (activeModal && activeModal !== modal) closePaymentModal(activeModal);
    lastTrigger = trigger || null;
    modal.hidden = false;
    requestAnimationFrame(function () { modal.classList.add('is-open'); });
    document.body.classList.add('pv-modal-open');
    activeModal = modal;
    var close = modal.querySelector('.pv-modal-close');
    if (close) close.focus();
  }

  function closePaymentModal(modal) {
    modal = modal || activeModal;
    if (!modal) return;
    modal.classList.remove('is-open');
    window.setTimeout(function () { modal.hidden = true; }, 160);
    document.body.classList.remove('pv-modal-open');
    activeModal = null;
    if (lastTrigger) lastTrigger.focus();
  }

  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-pv-open]');
    if (opener) {
      event.preventDefault();
      openPaymentModal(opener.getAttribute('data-pv-open'), opener);
      return;
    }
    if (event.target.closest('[data-pv-close]')) {
      event.preventDefault();
      closePaymentModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && activeModal) closePaymentModal();
  });

  <?php $autoOpenPayment = ($selectedId > 0 && (isset($_GET['saved']) || $error !== '')) ? $selectedId : 0; ?>
  var autoId = <?= (int)$autoOpenPayment ?>;
  if (autoId) {
    var trigger = document.querySelector('[data-pv-open="pv-payment-' + autoId + '"]');
    if (trigger) openPaymentModal('pv-payment-' + autoId, trigger);
  }
})();
</script>

<?php require_once '../includes/footer.php'; ?>
