<?php
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/payment_receipts.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/customer_auth.php';

startCustomerSession();
$portalCustomerSession = customerSessionProfile();
$customerHomeHref = $portalCustomerSession ? 'home.php' : '../index.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Payment not found.');
}

$stmt = $conn->prepare('SELECT payment_id FROM paymongo_checkouts WHERE return_token=?');
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
    exit('Payment not found.');
}

$paymentId = (int)$row['payment_id'];
$checkout = paymongoFind($conn, $paymentId);
$paymentMethodName = paymentMethodLabel($checkout);
$error = isset($_GET['wallet_error'])
    ? 'Wallet authorization could not be started. You can safely try the same payment again from this page.'
    : '';

if (($checkout['flow_type'] ?? 'hosted') === 'wallet'
    && !empty($checkout['payment_intent_id'])
    && !in_array($checkout['state'], ['paid', 'test_paid', 'review'], true)
    && (bool)$checkout['livemode'] === paymongoLive()) {
    $stmt = $conn->prepare('UPDATE paymongo_checkouts SET last_checked_at=NOW() WHERE payment_id=? AND (last_checked_at IS NULL OR last_checked_at < NOW() - INTERVAL 10 SECOND)');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();

    if ($stmt->affected_rows === 1) {
        try {
            $intent = paymongoRequest('GET', 'payment_intents/' . rawurlencode((string)$checkout['payment_intent_id']));
            $intentStatus = (string)($intent['attributes']['status'] ?? '');
            if ($intentStatus === 'succeeded') {
                paymongoSettleIntent($conn, $paymentId, $intent);
            } elseif ($intentStatus === 'awaiting_payment_method') {
                $stmt = $conn->prepare("UPDATE paymongo_checkouts SET state='open',payment_method_id=NULL,redirect_url=NULL WHERE payment_id=?");
                $stmt->bind_param('i', $paymentId);
                $stmt->execute();
            } elseif ($intentStatus === 'awaiting_next_action') {
                $redirect = (string)($intent['attributes']['next_action']['redirect']['url'] ?? '');
                if ($redirect !== '' && paymongoSafeExternalUrl($redirect)) {
                    $stmt = $conn->prepare("UPDATE paymongo_checkouts SET state='open',redirect_url=? WHERE payment_id=?");
                    $stmt->bind_param('si', $redirect, $paymentId);
                    $stmt->execute();
                }
            }
            $checkout = paymongoFind($conn, $paymentId);
        } catch (Throwable $e) {
            if ($error === '') {
                $error = 'Payment status could not be checked right now. Refresh shortly; do not make another payment if already charged.';
            }
        }
    }
} elseif ($checkout['session_id']
    && !in_array($checkout['state'], ['paid', 'test_paid', 'review'], true)
    && (bool)$checkout['livemode'] === paymongoLive()) {
    $stmt = $conn->prepare('UPDATE paymongo_checkouts SET last_checked_at=NOW() WHERE payment_id=? AND (last_checked_at IS NULL OR last_checked_at < NOW() - INTERVAL 15 SECOND)');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();

    if ($stmt->affected_rows === 1) {
        try {
            $session = paymongoRequest('GET', 'checkout_sessions/' . rawurlencode($checkout['session_id']));
            $settled = paymongoSettle($conn, $paymentId, $session);
            if (!$settled && ($session['attributes']['status'] ?? '') === 'expired') {
                $stmt = $conn->prepare("UPDATE paymongo_checkouts SET state='expired' WHERE payment_id=? AND state='open'");
                $stmt->bind_param('i', $paymentId);
                $stmt->execute();
            }
            $checkout = paymongoFind($conn, $paymentId);
        } catch (Throwable $e) {
            $error = 'Payment status could not be checked right now. Refresh shortly; do not make another payment if already charged.';
        }
    }
}

$stmt = $conn->prepare('SELECT o.order_number,o.status,o.fulfillment_method FROM orders o JOIN checkout_payment_orders po ON po.order_id=o.id WHERE po.payment_id=? ORDER BY o.id');
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$state = (string)($checkout['state'] ?? 'open');
$isConfirmed = in_array($state, ['paid', 'test_paid'], true);
$isTest = $state === 'test_paid';
$isReview = $state === 'review';
$isExpired = $state === 'expired';
$isCancelled = isset($_GET['cancelled']);
$isAdminVerified = (string)($checkout['payment_status'] ?? '') === 'verified';
$isDeliveryCheckout = strtolower((string)($orders[0]['fulfillment_method'] ?? ($checkout['fulfillment_method'] ?? 'pickup'))) === 'delivery';
$paymentKindLabel = $isDeliveryCheckout ? 'Full payment' : 'Downpayment';

// Successful payments must never stop on the legacy payment-received page.
// Always hand the customer back to My Orders, where the current Order Submitted
// modal and realtime tracking UI live. If the login session expired while the
// external wallet was open, orders.php will safely request login first.
if ($isConfirmed) {
    $issuedReceipt = ensureReceiptForPayment($conn, $paymentId);
    if ($issuedReceipt) {
        paymentReceiptEmail($conn, $paymentId);
    }
    $_SESSION['customer_order_success'] = [
        'order_numbers' => array_values(array_filter(array_map(static fn(array $order): string => (string)($order['order_number'] ?? ''), $orders))),
        'payment_method' => $paymentMethodName,
        'downpayment' => (float)($checkout['amount'] ?? 0),
        'test_mode' => $isTest,
        'paymongo_reference' => (string)($checkout['reference_number'] ?? ''),
        'paymongo_payment_id' => (string)($checkout['paid_payment_id'] ?? ''),
        'paymongo_intent_id' => (string)($checkout['payment_intent_id'] ?? ''),
        'paid_at' => date('Y-m-d H:i:s'),
        'receipt_number' => (string)($issuedReceipt['receipt_number'] ?? ''),
        'receipt_barcode' => (string)($issuedReceipt['barcode_value'] ?? ''),
    ];
    header('Location: orders.php?placed=1', true, 303);
    exit;
}

$orderStageRanks = [
    'pending' => 0,
    'in_production' => 1,
    'quality_check' => 2,
    'ready' => 3,
    'ready_for_pickup' => 3,
    'completed' => 4,
];
$progressRank = 0;
if ($orders) {
    $linkedRanks = [];
    foreach ($orders as $order) {
        $statusKey = strtolower((string)($order['status'] ?? 'pending'));
        if (array_key_exists($statusKey, $orderStageRanks)) {
            $linkedRanks[] = $orderStageRanks[$statusKey];
        }
    }
    if ($linkedRanks) {
        // Use the least-advanced linked order so the customer is never shown a stage
        // that every order in this checkout has not reached yet.
        $progressRank = min($linkedRanks);
    }
}
$isInProduction = $progressRank >= 1;
$isQualityCheck = $progressRank >= 2;
$isReady = $progressRank >= 3;
$isCompleted = $progressRank >= 4;
$adminComplete = $isAdminVerified || $isInProduction;

$progressSteps = [
    ['label' => 'Order Placed', 'state' => 'done', 'note' => 'Order saved'],
    ['label' => $isConfirmed ? 'Payment Confirmed' : 'Payment', 'state' => $isConfirmed ? 'done' : 'active', 'note' => $isConfirmed ? 'Confirmed by PayMongo' : 'Waiting for payment'],
    ['label' => 'Admin Verification', 'state' => $adminComplete ? 'done' : ($isConfirmed ? 'active' : 'upcoming'), 'note' => $adminComplete ? 'Verified' : ($isConfirmed ? 'In progress' : 'Pending')],
    ['label' => 'In Production', 'state' => $progressRank > 1 ? 'done' : ($progressRank === 1 ? 'active' : 'upcoming'), 'note' => $progressRank >= 1 ? ($progressRank === 1 ? 'Current stage' : 'Completed') : 'Pending'],
    ['label' => 'Quality Check', 'state' => $progressRank > 2 ? 'done' : ($progressRank === 2 ? 'active' : 'upcoming'), 'note' => $progressRank >= 2 ? ($progressRank === 2 ? 'Current stage' : 'Completed') : 'Pending'],
    ['label' => 'Ready', 'state' => $progressRank > 3 ? 'done' : ($progressRank === 3 ? 'active' : 'upcoming'), 'note' => $progressRank >= 3 ? ($progressRank === 3 ? 'Ready for release' : 'Completed') : 'Pending'],
    ['label' => 'Completed', 'state' => $isCompleted ? 'done current' : 'upcoming', 'note' => $isCompleted ? 'Order completed' : 'Pending'],
];

$title = 'Payment pending';
$message = 'Waiting for PayMongo payment confirmation.';
$badge = 'Waiting for Payment';
$iconClass = 'waiting';

if ($isConfirmed) {
    $title = 'Payment received successfully';
    if ($isAdminVerified || $isInProduction) {
        $message = 'Your ' . strtolower($paymentKindLabel) . ' has been verified by the admin and your order can now continue through production.';
        $badge = 'Admin Verified';
    } else {
        $message = $isTest
            ? 'Your test payment was received. Our admin will verify and credit the ' . strtolower($paymentKindLabel) . ' before your order moves to production.'
            : 'Your ' . $paymentMethodName . ' payment was confirmed by PayMongo. Our admin will verify and credit the ' . strtolower($paymentKindLabel) . ' before your order moves to production.';
        $badge = 'Awaiting Admin Verification';
    }
    $iconClass = 'success';
} elseif ($isReview) {
    $title = 'Payment needs review';
    $message = 'PayMongo received the payment, but the shop needs to review the linked order details before it can continue.';
    $badge = 'Admin Review Required';
    $iconClass = 'review';
} elseif ($isExpired) {
    $title = 'Checkout expired';
    $message = 'This checkout has expired. Your order is still saved. Please contact the shop before making another payment.';
    $badge = 'Checkout Expired';
    $iconClass = 'review';
} elseif ($isCancelled) {
    $title = 'Payment not completed';
    $message = 'The checkout was interrupted. Your order is saved and no payment has been credited.';
    $badge = 'Payment Pending';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="referrer" content="no-referrer">
  <title><?= htmlspecialchars($title) ?> - Brille Amour</title>
  <link rel="stylesheet" href="../assets/css/paymongo-status.css?v=<?= filemtime(__DIR__ . '/../assets/css/paymongo-status.css') ?>">
  <link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . "/../assets/css/system-ux-v2.css") ?>">
<link rel="stylesheet" href="../assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-comfort.css') ?>">
<link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body class="pm-status-body">
<header class="pm-site-header">
  <a class="pm-brand" href="<?= htmlspecialchars($customerHomeHref) ?>" aria-label="Brille Amour home">
    <img src="../assets/img/image.png" alt="Brille Amour logo">
    <span class="pm-brand-copy"><strong>Brille Amour</strong><small>Prints &amp; Crafts</small></span>
  </a>
  <nav class="pm-nav" aria-label="Customer navigation">
    <a href="<?= htmlspecialchars($customerHomeHref) ?>">Home</a>
    <a href="orders.php">My Orders</a>
    <a class="pm-order-now" href="shop.php">Shop</a>
  </nav>
</header>

<main class="pm-status-wrap">
  <?php if ($isTest): ?>
    <div class="pm-test-pill">Test Mode - No real money collected</div>
  <?php endif; ?>

  <div class="pm-page-title">
    <h1><?= $isConfirmed ? htmlspecialchars($paymentMethodName . ' Payment Received') : htmlspecialchars($title) ?></h1>
    <p><?= $isConfirmed ? 'Thank you for your order' : 'Brille Amour Payment Status' ?></p>
  </div>

  <section class="pm-main-card" aria-labelledby="payment-title">
    <div class="pm-success-hero">
      <div class="pm-icon-orbit <?= htmlspecialchars($iconClass) ?>" aria-hidden="true"><span class="pm-icon-mark"></span></div>
      <div class="pm-hero-copy">
        <h2 id="payment-title"><?= htmlspecialchars($title) ?></h2>
        <div class="pm-state-pill <?= ($isAdminVerified || $isInProduction) ? 'success' : '' ?>"><?= htmlspecialchars($badge) ?></div>
        <p role="status"><?= htmlspecialchars($message) ?></p>
      </div>
    </div>

    <?php if ($error): ?><div class="pm-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="pm-content-grid">
      <section class="pm-subcard" aria-label="Order summary">
        <div class="pm-subcard-head">Order Summary</div>
        <div class="pm-summary">
          <?php if ($orders): ?>
            <div class="pm-row"><span>Order No.</span><strong class="pm-order-links"><?php foreach ($orders as $order): ?><a class="pm-order-link" href="orders.php"><?= htmlspecialchars($order['order_number']) ?></a><?php endforeach; ?></strong></div>
          <?php endif; ?>
          <div class="pm-row"><span>Checkout Reference</span><strong><?= htmlspecialchars($checkout['reference_number']) ?></strong></div>
          <div class="pm-row"><span><?= htmlspecialchars($paymentKindLabel) ?></span><strong><?= formatMoney($checkout['amount']) ?></strong></div>
          <div class="pm-row"><span>Payment Method</span><strong><?= htmlspecialchars($paymentMethodName) ?><?= $isTest ? ' - Test' : '' ?></strong></div>
        </div>
      </section>

      <section class="pm-subcard" aria-label="Order progress">
        <div class="pm-subcard-head">Order Progress</div>
        <div class="pm-progress">
          <div class="pm-stepper">
            <?php foreach ($progressSteps as $stepIndex => $step): ?>
              <div class="pm-step <?= htmlspecialchars($step['state']) ?>">
                <div class="pm-step-dot" aria-hidden="true"><span class="pm-step-num"><?= $stepIndex + 1 ?></span><span class="pm-step-check"></span></div>
                <strong><?= htmlspecialchars($step['label']) ?></strong>
                <span><?= htmlspecialchars($step['note']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    </div>

    <div class="pm-actions">
      <?php if ($checkout['state'] === 'open' && (bool)$checkout['livemode'] === paymongoLive()): ?>
        <?php if (($checkout['flow_type'] ?? 'hosted') === 'wallet' && paymongoDirectWalletReady()): ?>
          <a class="pm-btn primary" href="paymongo-wallet-start.php?token=<?= htmlspecialchars($token) ?>">Continue with <?= htmlspecialchars($paymentMethodName) ?></a>
        <?php elseif (paymongoCheckoutUrl((string)$checkout['checkout_url'])): ?>
          <a class="pm-btn primary" href="<?= htmlspecialchars($checkout['checkout_url']) ?>">Continue Payment</a>
        <?php endif; ?>
      <?php endif; ?>
      <a class="pm-btn primary" href="paymongo-return.php?token=<?= htmlspecialchars($token) ?>">Refresh Status</a>
      <a class="pm-btn" href="orders.php">View My Orders</a>
      <a class="pm-btn link" href="shop.php">Back to Shop</a>
    </div>

    <div class="pm-privacy">Keep this page link private. It gives access to this checkout's payment status.</div>
  </section>
  <div class="pm-foot">Brille Amour - Prints &amp; Crafts</div>
</main>
<script src="../assets/js/public-ajax.js?v=<?= filemtime(__DIR__ . "/../assets/js/public-ajax.js") ?>"></script>
</body>
</html>
