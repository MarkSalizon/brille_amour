<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/functions.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: login.php?return=' . urlencode('bill.php?order=' . (string)($_GET['order'] ?? '')));
    exit;
}

$orderNumber = trim((string)($_GET['order'] ?? ''));
if ($orderNumber === '') {
    header('Location: orders.php');
    exit;
}

$customerId = (int)$customer['id'];
$name = trim((string)$customer['name']);
$contact = trim((string)($customer['contact'] ?? ''));

if ($contact !== '') {
    $stmt = $conn->prepare("SELECT id, order_number, customer_name, customer_contact, source, order_type, description, quantity, unit_price, total_amount, deposit_paid, balance, due_date, pickup_time, status, notes, created_at, updated_at FROM orders WHERE order_number = ? AND status IN ('ready','ready_for_pickup') AND (customer_id = ? OR (customer_id IS NULL AND LOWER(TRIM(customer_name)) = LOWER(TRIM(?)) AND customer_contact = ?)) LIMIT 1");
    $stmt->bind_param('siss', $orderNumber, $customerId, $name, $contact);
} else {
    $stmt = $conn->prepare("SELECT id, order_number, customer_name, customer_contact, source, order_type, description, quantity, unit_price, total_amount, deposit_paid, balance, due_date, pickup_time, status, notes, created_at, updated_at FROM orders WHERE order_number = ? AND status IN ('ready','ready_for_pickup') AND customer_id = ? LIMIT 1");
    $stmt->bind_param('si', $orderNumber, $customerId);
}
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    header('Location: orders.php');
    exit;
}

$total = (float)$order['total_amount'];
$balance = max(0, (float)$order['balance']);
$paid = max(0, $total - $balance);
$deposit = max(0, (float)$order['deposit_paid']);
$fullyPaid = $balance <= 0.009;
$readyAt = !empty($order['updated_at']) ? strtotime((string)$order['updated_at']) : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Bill <?= htmlspecialchars((string)$order['order_number']) ?> - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body class="customer-bill-body">
  <main class="customer-bill-shell">
    <div class="bill-page-actions no-print">
      <a href="orders.php#order-<?= htmlspecialchars(preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$order['order_number'])) ?>">← Back to My Orders</a>
      <button type="button" onclick="window.print()">Print Bill</button>
    </div>

    <article class="customer-bill-paper">
      <header class="bill-header">
        <div class="bill-brand">
          <img src="../assets/img/image.png" alt="Brille Amour logo">
          <div><strong>Brille Amour</strong><span>Prints &amp; Crafts</span></div>
        </div>
        <div class="bill-title">
          <span>ORDER BILL</span>
          <strong><?= htmlspecialchars((string)$order['order_number']) ?></strong>
          <small><?= date('M d, Y · g:i A', $readyAt) ?></small>
        </div>
      </header>

      <section class="bill-status-strip <?= $fullyPaid ? 'is-paid' : 'has-balance' ?>">
        <div><span>Order Status</span><strong>Ready</strong></div>
        <div><span>Payment Status</span><strong><?= $fullyPaid ? 'Paid in Full' : 'Balance Due' ?></strong></div>
      </section>

      <section class="bill-info-grid">
        <div>
          <span class="bill-section-label">Billed To</span>
          <strong><?= htmlspecialchars((string)$order['customer_name']) ?></strong>
          <?php if (!empty($customer['email'])): ?><small><?= htmlspecialchars((string)$customer['email']) ?></small><?php endif; ?>
          <?php if (!empty($order['customer_contact'])): ?><small><?= htmlspecialchars((string)$order['customer_contact']) ?></small><?php endif; ?>
          <?php if (!empty($customer['address'])): ?><small><?= nl2br(htmlspecialchars((string)$customer['address'])) ?></small><?php endif; ?>
        </div>
        <div>
          <span class="bill-section-label">Order Details</span>
          <small><b>Ordered:</b> <?= date('M d, Y', strtotime((string)$order['created_at'])) ?></small>
          <small><b>Ready:</b> <?= date('M d, Y', $readyAt) ?></small>
          <small><b>Pickup:</b> <?= !empty($order['due_date']) ? date('M d, Y', strtotime((string)$order['due_date'])) : 'Not specified' ?><?= !empty($order['pickup_time']) ? ' · ' . htmlspecialchars((string)$order['pickup_time']) : '' ?></small>
        </div>
      </section>

      <section class="bill-line-items">
        <div class="bill-line-items-head"><span>Item / Service</span><span>Qty</span><span>Unit Price</span><span>Amount</span></div>
        <div class="bill-line-item">
          <div><strong><?= htmlspecialchars((string)$order['order_type']) ?></strong><?php if (trim((string)$order['description']) !== ''): ?><small><?= nl2br(htmlspecialchars((string)$order['description'])) ?></small><?php endif; ?></div>
          <span><?= (int)$order['quantity'] ?></span>
          <span><?= formatMoney((float)$order['unit_price']) ?></span>
          <strong><?= formatMoney($total) ?></strong>
        </div>
      </section>

      <section class="bill-totals">
        <div><span>Order Total</span><strong><?= formatMoney($total) ?></strong></div>
        <div><span>Downpayment Credited</span><strong><?= formatMoney($deposit) ?></strong></div>
        <div><span>Total Paid / Credited</span><strong><?= formatMoney($paid) ?></strong></div>
        <div class="bill-grand-total"><span>Remaining Balance</span><strong><?= formatMoney($balance) ?></strong></div>
      </section>

      <?php if (!$fullyPaid): ?>
        <div class="bill-balance-note">This ready order still has a recorded remaining balance of <strong><?= formatMoney($balance) ?></strong>. Please coordinate settlement with Brille Amour.</div>
      <?php else: ?>
        <div class="bill-paid-note">Payment record shows this order as fully settled.</div>
      <?php endif; ?>

      <footer class="bill-footer">
        <p>Thank you for choosing Brille Amour Prints &amp; Crafts.</p>
        <small>This bill is generated from the order information recorded in your Brille Amour customer account.</small>
      </footer>
    </article>
  </main>
</html>
