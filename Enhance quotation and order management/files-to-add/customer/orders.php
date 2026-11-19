<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/functions.php';
require_once '../includes/messaging.php';
require_once '../includes/admin_settings.php';
require_once '../includes/production_capacity.php';
require_once '../includes/product_images.php';
require_once '../includes/innovation.php';
ensureInnovationSchema($conn);
require_once '../includes/payment_receipts.php';
require_once '../includes/order_feedback.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
ensureProductionStageQueueSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: login.php?return=orders.php');
    exit;
}

$customerId = (int)$customer['id'];
$name = trim((string)$customer['name']);
$contact = trim((string)($customer['contact'] ?? ''));
$orders = [];
if ($contact !== '') {
    $stmt = $conn->prepare("SELECT o.id, o.order_number, o.order_type, o.product_id, o.image, o.description, o.quantity, o.unit_price, o.total_amount, o.delivery_fee, o.deposit_paid, o.balance, o.due_date, o.pickup_time, o.fulfillment_method, o.delivery_address, o.status, o.created_at, o.updated_at, o.customer_name, o.customer_contact, q.target_stage AS queue_target, p.name AS current_product_name, p.image AS current_product_image FROM orders o LEFT JOIN production_stage_queue q ON q.order_id=o.id LEFT JOIN products p ON p.id=o.product_id WHERE o.status <> 'archived' AND (o.customer_id = ? OR (o.customer_id IS NULL AND LOWER(TRIM(o.customer_name)) = LOWER(TRIM(?)) AND o.customer_contact = ?)) ORDER BY o.created_at DESC, o.id DESC LIMIT 100");
    $stmt->bind_param('iss', $customerId, $name, $contact);
} else {
    $stmt = $conn->prepare("SELECT o.id, o.order_number, o.order_type, o.product_id, o.image, o.description, o.quantity, o.unit_price, o.total_amount, o.delivery_fee, o.deposit_paid, o.balance, o.due_date, o.pickup_time, o.fulfillment_method, o.delivery_address, o.status, o.created_at, o.updated_at, o.customer_name, o.customer_contact, q.target_stage AS queue_target, p.name AS current_product_name, p.image AS current_product_image FROM orders o LEFT JOIN production_stage_queue q ON q.order_id=o.id LEFT JOIN products p ON p.id=o.product_id WHERE o.status <> 'archived' AND o.customer_id = ? ORDER BY o.created_at DESC, o.id DESC LIMIT 100");
    $stmt->bind_param('i', $customerId);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
$stmt->close();

// Track PayMongo-paid orders so customers can reopen their payment receipt later.
$paymongoReceiptOrders = [];
$orderIdsForReceipt = array_values(array_filter(array_map(static fn(array $order): int => (int)($order['id'] ?? 0), $orders)));
if ($orderIdsForReceipt) {
    $idList = implode(',', array_map('intval', $orderIdsForReceipt));
    $receiptSql = "SELECT po.order_id FROM checkout_payment_orders po JOIN paymongo_checkouts g ON g.payment_id=po.payment_id WHERE po.order_id IN ($idList) AND g.state IN ('paid','test_paid')";
    if ($receiptResult = $conn->query($receiptSql)) {
        while ($receiptRow = $receiptResult->fetch_assoc()) {
            $paymongoReceiptOrders[(int)$receiptRow['order_id']] = true;
        }
        $receiptResult->free();
    }
}

$feedbackByOrder = orderFeedbackMap($conn, $orderIdsForReceipt);
$feedbackCsrf = customerAuthCsrfToken();


/**
 * Render a compact Code 39 barcode SVG for receipt/order references.
 */
function brilleReceiptBarcodeValue(string $orderNumber, string $reference = ''): string
{
    $orderNumber = strtoupper(trim($orderNumber));
    $reference = strtoupper(trim($reference));
    $parts = [];
    if ($orderNumber !== '') {
        $parts[] = 'BA-' . $orderNumber;
    }
    if ($reference !== '') {
        $reference = preg_replace('/[^A-Z0-9.\-\$\/\+% ]+/', '', $reference) ?? '';
        $reference = trim($reference);
        if ($reference !== '') {
            $parts[] = substr($reference, -8);
        }
    }
    $value = strtoupper(implode('-', $parts));
    $value = preg_replace('/[^A-Z0-9.\-\$\/\+% ]+/', '', $value) ?? '';
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        $value = 'BA-RECEIPT';
    }
    return substr($value, 0, 42);
}

function brilleRenderCode39Svg(string $value, int $height = 74): string
{
    static $patterns = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9.\-\$\/\+% ]+/', '', $value) ?? '';
    if ($value === '') {
        $value = 'BA-RECEIPT';
    }
    $encoded = '*' . $value . '*';
    $narrow = 2;
    $wide = 5;
    $gap = 2;
    $quiet = 12;
    $textSpace = 18;

    $width = $quiet * 2;
    foreach (str_split($encoded) as $char) {
        $pattern = $patterns[$char] ?? $patterns['*'];
        foreach (str_split($pattern) as $symbol) {
            $width += ($symbol === 'w' ? $wide : $narrow);
        }
        $width += $gap;
    }

    $x = $quiet;
    $rects = [];
    foreach (str_split($encoded) as $char) {
        $pattern = $patterns[$char] ?? $patterns['*'];
        foreach (str_split($pattern) as $i => $symbol) {
            $barWidth = ($symbol === 'w' ? $wide : $narrow);
            if ($i % 2 == 0) {
                $rects[] = '<rect x="' . $x . '" y="0" width="' . $barWidth . '" height="' . $height . '" rx="0.6" ry="0.6" fill="#2f2328" />';
            }
            $x += $barWidth;
        }
        $x += $gap;
    }

    $svgHeight = $height + $textSpace;
    $labelY = $height + 13;
    return '<svg class="receipt-barcode-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $svgHeight . '" role="img" aria-label="Receipt barcode ' . htmlspecialchars($value, ENT_QUOTES) . '">' . implode('', $rects) . '<text x="' . ($width / 2) . '" y="' . $labelY . '" text-anchor="middle" font-family="Outfit,Arial,sans-serif" font-size="11" letter-spacing="2.2" fill="#6b5560">' . htmlspecialchars($value, ENT_QUOTES) . '</text></svg>';
}

$designProofByOrder=[]; $deliveryByOrder=[];
if ($orderIdsForReceipt) {
  $idList = implode(',', array_map('intval', $orderIdsForReceipt));
  if($rr=$conn->query("SELECT dp.* FROM design_proofs dp JOIN (SELECT order_id,MAX(id) id FROM design_proofs WHERE order_id IN ($idList) GROUP BY order_id) x ON x.id=dp.id")) while($x=$rr->fetch_assoc()) $designProofByOrder[(int)$x['order_id']]=$x;
  if($rr=$conn->query("SELECT * FROM delivery_tracking WHERE order_id IN ($idList)")) while($x=$rr->fetch_assoc()) $deliveryByOrder[(int)$x['order_id']]=$x;
}

/**
 * Keep older/legacy status values grouped with the current customer-facing stage.
 */
function customerPurchaseStatusKey(string $status): string
{
    $status = strtolower(trim($status));
    $aliases = [
        'processing' => 'in_production',
        'ready_for_pickup' => 'ready',
    ];
    return $aliases[$status] ?? $status;
}

function customerPurchaseFilterKey(array $order, array $deliveryByOrder): string
{
    $status = customerPurchaseStatusKey((string)($order['status'] ?? 'pending'));
    if ($status !== 'cancelled' && strtolower((string)($order['fulfillment_method'] ?? 'pickup')) === 'delivery') {
        $delivery = (string)($deliveryByOrder[(int)$order['id']]['status'] ?? '');
        if ($delivery === 'out_for_delivery') return 'out_for_delivery';
        if ($delivery === 'delivered') return 'completed';
    }
    return $status;
}

$purchaseStages = [
    'pending' => 'Pending',
    'in_production' => 'In Production',
    'quality_check' => 'Quality Check',
    'ready' => 'Ready',
    'out_for_delivery' => 'Out for Delivery',
    'completed' => 'Completed',
];
$purchaseCounts = array_fill_keys(array_keys($purchaseStages), 0);
foreach ($orders as $order) {
    $key = customerPurchaseFilterKey($order, $deliveryByOrder);
    if (array_key_exists($key, $purchaseCounts)) {
        $purchaseCounts[$key]++;
    }
}

$selectedStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!array_key_exists($selectedStatus, $purchaseStages)) {
    $selectedStatus = 'all';
}
$displayOrders = $orders;
if ($selectedStatus !== 'all') {
    $displayOrders = array_values(array_filter($orders, static function (array $order) use ($selectedStatus, $deliveryByOrder): bool {
        return customerPurchaseFilterKey($order, $deliveryByOrder) === $selectedStatus;
    }));
}

$orderSuccess = null;
if (isset($_GET['placed']) && (string)$_GET['placed'] === '1' && !empty($_SESSION['customer_order_success']) && is_array($_SESSION['customer_order_success'])) {
    $orderSuccess = $_SESSION['customer_order_success'];
    unset($_SESSION['customer_order_success']);
}

$activePage = 'orders';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>My Orders - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/customer-auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-auth.css') ?>">
  <link rel="stylesheet" href="../assets/css/product-images.css?v=<?= filemtime(__DIR__ . '/../assets/css/product-images.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-portal.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-portal.css') ?>">
  <link rel="stylesheet" href="../assets/css/customer-orders.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer-orders.css') ?>">
  <link rel="stylesheet" href="../assets/css/overflow-guard.css?v=20260916">
<style>.innovation-order-actions{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}.innovation-order-actions a,.innovation-order-actions span{font-size:12px;padding:6px 9px;border-radius:9px;background:#fff3f6;color:#7d3f55;text-decoration:none;font-weight:700}</style>  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body class="customer-account-body customer-portal-body">
  <?php $portalActive = 'orders'; require __DIR__ . '/portal_header.php'; ?>
  <main class="customer-account-content">

      <section class="account-page-heading compact-heading">
        <div><span class="auth-eyebrow">Order Center</span><h1>My Orders</h1><p>Track your orders from production to pickup or delivery.</p></div>
        <a class="account-primary-action" href="shop.php">Shop Products</a>
      </section>

      <section class="purchase-status-panel" aria-label="My purchases">
        <div class="purchase-status-heading">
          <h2>My Purchases</h2>
          <a class="purchase-history-link <?= $selectedStatus === 'all' ? 'is-active' : '' ?>" href="orders.php#orders-list" data-no-loader>
            <span>View Purchase History</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
          </a>
        </div>

        <nav class="purchase-status-grid" aria-label="Filter orders by production stage">
          <a class="purchase-status-item <?= $selectedStatus === 'pending' ? 'is-active' : '' ?>" href="orders.php?status=pending#orders-list" data-no-loader <?= $selectedStatus === 'pending' ? 'aria-current="page"' : '' ?>>
            <span class="purchase-status-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48"><path d="M11 13h26v25H11z"/><path d="M16 13V9h16v4M17 24h14M17 30h9"/><circle cx="35" cy="35" r="7"/><path d="M35 31v4l3 2"/></svg>
              <span class="purchase-status-count" data-stage-count="pending" <?= $purchaseCounts['pending'] > 0 ? '' : 'hidden' ?>><?= (int)$purchaseCounts['pending'] ?></span>
            </span>
            <span class="purchase-status-label">Pending</span>
          </a>

          <a class="purchase-status-item <?= $selectedStatus === 'in_production' ? 'is-active' : '' ?>" href="orders.php?status=in_production#orders-list" data-no-loader <?= $selectedStatus === 'in_production' ? 'aria-current="page"' : '' ?>>
            <span class="purchase-status-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M21 6h6l1.1 5 3.3 1.4 4.3-2.7 4.2 4.2-2.7 4.3 1.4 3.3 5 1.1v6l-5 1.1-1.4 3.3 2.7 4.3-4.2 4.2-4.3-2.7-3.3 1.4-1.1 5h-6l-1.1-5-3.3-1.4-4.3 2.7-4.2-4.2 2.7-4.3-1.4-3.3-5-1.1v-6l5-1.1 1.4-3.3-2.7-4.3 4.2-4.2 4.3 2.7 3.3-1.4z"/><circle cx="24" cy="25.5" r="6"/></svg>
              <span class="purchase-status-count" data-stage-count="in_production" <?= $purchaseCounts['in_production'] > 0 ? '' : 'hidden' ?>><?= (int)$purchaseCounts['in_production'] ?></span>
            </span>
            <span class="purchase-status-label">In Production</span>
          </a>

          <a class="purchase-status-item <?= $selectedStatus === 'quality_check' ? 'is-active' : '' ?>" href="orders.php?status=quality_check#orders-list" data-no-loader <?= $selectedStatus === 'quality_check' ? 'aria-current="page"' : '' ?>>
            <span class="purchase-status-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48"><circle cx="21" cy="21" r="11"/><path d="m29 29 9 9M16 21l4 4 7-8"/></svg>
              <span class="purchase-status-count" data-stage-count="quality_check" <?= $purchaseCounts['quality_check'] > 0 ? '' : 'hidden' ?>><?= (int)$purchaseCounts['quality_check'] ?></span>
            </span>
            <span class="purchase-status-label">Quality Check</span>
          </a>

          <a class="purchase-status-item <?= $selectedStatus === 'ready' ? 'is-active' : '' ?>" href="orders.php?status=ready#orders-list" data-no-loader <?= $selectedStatus === 'ready' ? 'aria-current="page"' : '' ?>>
            <span class="purchase-status-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48"><path d="m24 6 17 9v19l-17 9-17-9V15zM7 15l17 9 17-9M24 24v19M15 10l18 9"/></svg>
              <span class="purchase-status-count" data-stage-count="ready" <?= $purchaseCounts['ready'] > 0 ? '' : 'hidden' ?>><?= (int)$purchaseCounts['ready'] ?></span>
            </span>
            <span class="purchase-status-label">Ready</span>
          </a>

          <a class="purchase-status-item <?= $selectedStatus === 'out_for_delivery' ? 'is-active' : '' ?>" href="orders.php?status=out_for_delivery#orders-list" data-no-loader <?= $selectedStatus === 'out_for_delivery' ? 'aria-current="page"' : '' ?>>
            <span class="purchase-status-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48"><path d="M7 16h23v18H7zM30 22h6l5 6v6H30zM4 10h19M2 22h3"/><circle cx="15" cy="36" r="4"/><circle cx="35" cy="36" r="4"/></svg>
              <span class="purchase-status-count" data-stage-count="out_for_delivery" <?= $purchaseCounts['out_for_delivery'] > 0 ? '' : 'hidden' ?>><?= (int)$purchaseCounts['out_for_delivery'] ?></span>
            </span>
            <span class="purchase-status-label">Out for Delivery</span>
          </a>

          <a class="purchase-status-item <?= $selectedStatus === 'completed' ? 'is-active' : '' ?>" href="orders.php?status=completed#orders-list" data-no-loader <?= $selectedStatus === 'completed' ? 'aria-current="page"' : '' ?>>
            <span class="purchase-status-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="16"/><path d="m16 24 5 5 11-12"/></svg>
              <span class="purchase-status-count" data-stage-count="completed" <?= $purchaseCounts['completed'] > 0 ? '' : 'hidden' ?>><?= (int)$purchaseCounts['completed'] ?></span>
            </span>
            <span class="purchase-status-label">Completed</span>
          </a>
        </nav>
      </section>

      <section class="orders-results" id="orders-list" aria-label="Your orders" data-receipt-live-list data-live-api="receipt_live_api.php?list=1" data-selected-status="<?= htmlspecialchars($selectedStatus) ?>" data-selected-label="<?= htmlspecialchars($selectedStatus === 'all' ? 'Purchase History' : ($purchaseStages[$selectedStatus] ?? 'Orders')) ?>">
        <?php if (!$orders): ?>
          <div class="orders-compact-empty"><span>No purchases yet.</span><a href="shop.php">Browse Shop</a></div>
        <?php else: ?>
          <div class="orders-compact-empty" id="ordersFilterEmpty" <?= $displayOrders ? 'hidden' : '' ?>><span>No <?= htmlspecialchars($selectedStatus === 'all' ? 'matching' : ($purchaseStages[$selectedStatus] ?? 'matching')) ?> orders right now.</span><a href="orders.php#orders-list">View All Orders</a></div>
          <div class="account-order-list account-tracker-list" id="customerOrdersLiveList">
            <?php foreach ($orders as $order): ?>
              <?php
                $orderStatus = (string)($order['status'] ?? 'pending');
                $displayStatus = customerPurchaseStatusKey($orderStatus);
                $statusLabel = $purchaseStages[$displayStatus] ?? ucwords(str_replace('_', ' ', $displayStatus));
                $queueTarget = (string)($order['queue_target'] ?? '');
                $waitingForProduction = ($displayStatus === 'pending' && (float)($order['deposit_paid'] ?? 0) > 0.001) || $queueTarget === 'in_production';
                $waitingForQualityCheck = $queueTarget === 'quality_check';
                if ($waitingForQualityCheck) {
                    $statusLabel = 'Waiting for Quality Check';
                } elseif ($waitingForProduction) {
                    $statusLabel = 'Waiting for Production';
                }
                $fulfillmentMethod = strtolower((string)($order['fulfillment_method'] ?? 'pickup')) === 'delivery' ? 'delivery' : 'pickup';
                $deliveryState = strtolower((string)($deliveryByOrder[(int)$order['id']]['status'] ?? ''));
                if ($fulfillmentMethod === 'delivery') {
                    if ($deliveryState === 'out_for_delivery') $statusLabel = 'Out for Delivery';
                    elseif ($deliveryState === 'delivered') $statusLabel = 'Delivered';
                    elseif ($displayStatus === 'ready') $statusLabel = 'Ready for Dispatch';
                    elseif ($displayStatus === 'completed' && $deliveryState === 'delivered') $statusLabel = 'Delivered';
                } elseif ($displayStatus === 'ready') {
                    $statusLabel = 'Ready for Pickup';
                }
                $orderAnchor = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$order['order_number']);
                $purchaseFilterKey = customerPurchaseFilterKey($order, $deliveryByOrder);
                $matchesSelected = $selectedStatus === 'all' || $purchaseFilterKey === $selectedStatus;
                $orderFeedback = $feedbackByOrder[(int)$order['id']] ?? null;
                $feedbackRating = (int)($orderFeedback['rating'] ?? 0);
                $feedbackText = (string)($orderFeedback['feedback'] ?? '');
              ?>
              <article class="account-order-tracker-card" id="order-<?= htmlspecialchars($orderAnchor) ?>" data-order-id="<?= (int)$order['id'] ?>" data-order-number="<?= htmlspecialchars((string)$order['order_number']) ?>" data-product-id="<?= (int)($order['product_id'] ?? 0) ?>" data-order-status="<?= htmlspecialchars($displayStatus) ?>" data-fulfillment-method="<?= htmlspecialchars($fulfillmentMethod) ?>" data-delivery-status="<?= htmlspecialchars($deliveryState) ?>" data-waiting-production="<?= $waitingForProduction ? '1' : '0' ?>" data-waiting-quality-check="<?= $waitingForQualityCheck ? '1' : '0' ?>" <?= $matchesSelected ? '' : 'hidden' ?>>
                <?php
                  $productName = trim((string)($order['order_type'] ?? '')) ?: 'Custom Product';
                  $currentProductName = trim((string)($order['current_product_name'] ?? '')) ?: $productName;
                  $sampleIndex = productSampleImageIndex($currentProductName);
                  $previewImage = productDisplayImage(['name' => $currentProductName, 'image' => (string)($order['current_product_image'] ?? '')]);
                  $uploadedImage = basename((string)($order['image'] ?? ''));
                  $hasUploadedImage = $uploadedImage !== ''
                      && preg_match('/\.(?:png|jpe?g|webp|gif)$/i', $uploadedImage)
                      && is_file(__DIR__ . '/../uploads/orders/' . $uploadedImage);
                ?>
                <div class="purchase-order-main">
                  <div class="purchase-product-art">
                    <?php if ($previewImage !== null): ?>
                      <img class="catalog-product-preview" src="../<?= htmlspecialchars($previewImage, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($productName) ?> product sample" loading="lazy">
                    <?php elseif ($sampleIndex !== null): ?>
                      <div class="product-sample-image" role="img" aria-label="<?= htmlspecialchars($productName) ?> sample design" style="background-position:<?= ($sampleIndex % 5) * 25 ?>% <?= floor($sampleIndex / 5) * 100 / 3 ?>%"></div>
                    <?php elseif ($hasUploadedImage): ?>
                      <img src="../uploads/orders/<?= rawurlencode($uploadedImage) ?>" alt="<?= htmlspecialchars($productName) ?> reference image" loading="lazy">
                    <?php else: ?>
                      <div class="purchase-product-fallback" aria-hidden="true"><?= htmlspecialchars(strtoupper(mb_substr($productName, 0, 1))) ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="purchase-product-info">
                    <span class="purchase-order-ref">Order #<?= htmlspecialchars((string)$order['order_number']) ?> · <?= date('M d, Y', strtotime((string)$order['created_at'])) ?></span>
                    <h3 data-order-product-name><?= htmlspecialchars($productName) ?></h3>
                    <span class="purchase-product-qty">Quantity: <?= (int)$order['quantity'] ?></span>
                    <span class="purchase-product-fulfillment"><?= $fulfillmentMethod === 'delivery' ? 'Delivery' : 'Pickup' ?><?= !empty($order['due_date']) ? ' · ' . date('M d, Y', strtotime((string)$order['due_date'])) : '' ?><?= $fulfillmentMethod === 'delivery' && (float)($order['delivery_fee'] ?? 0) > 0 ? ' · Fee ' . formatMoney((float)$order['delivery_fee']) : '' ?></span>
                    <div class="order-queue-note" data-order-queue-note <?= ($waitingForProduction || $waitingForQualityCheck) ? '' : 'hidden' ?>><?= $waitingForQualityCheck ? 'Waiting for an available Quality Check slot.' : 'Waiting for an available Production slot.' ?></div>
                  </div>
                  <div class="purchase-product-side">
                    <span class="order-status status-<?= htmlspecialchars($displayStatus) ?>" data-order-live-status><?= htmlspecialchars($statusLabel) ?></span>
                    <strong class="purchase-product-total"><?= formatMoney((float)$order['total_amount']) ?></strong>
                    <?php if ((float)$order['balance'] > 0.009): ?><small>Balance: <?= formatMoney((float)$order['balance']) ?></small><?php endif; ?>
                  </div>
                </div>
                <div class="purchase-order-footer">
                  <span class="order-last-updated" data-order-live-updated>Updated <?= !empty($order['updated_at']) ? htmlspecialchars(timeAgo((string)$order['updated_at'])) : 'recently' ?></span>
                  <div class="innovation-order-actions">
                    <a data-order-reorder href="reorder.php?order_id=<?= (int)$order['id'] ?>" <?= $purchaseFilterKey === 'completed' ? '' : 'hidden' ?>>Order Again</a>
                    <?php if(isset($designProofByOrder[(int)$order['id']])): ?><a href="design_approval.php?order_id=<?= (int)$order['id'] ?>">Design <?= htmlspecialchars(ucwords(str_replace('_',' ',$designProofByOrder[(int)$order['id']]['status']))) ?></a><?php endif; ?>
                    <?php if($fulfillmentMethod==='delivery' && ($deliveryState!=='' || $displayStatus==='ready')): ?><span><?= htmlspecialchars($deliveryState==='out_for_delivery'?'Out for Delivery':($deliveryState==='delivered'?'Delivered':($displayStatus==='ready'?'Ready for Dispatch':ucwords(str_replace('_',' ',$deliveryState))))) ?></span><?php endif; ?>
                  </div>
                  <div class="purchase-feedback-wrap" data-order-feedback-wrap <?= $purchaseFilterKey === 'completed' ? '' : 'hidden' ?>>
                    <span class="purchase-feedback-summary" data-feedback-summary <?= $feedbackRating > 0 ? '' : 'hidden' ?>>
                      <span class="feedback-stars-text" data-feedback-stars-text><?= htmlspecialchars(orderFeedbackStars($feedbackRating)) ?></span>
                      <span data-feedback-rating-label><?= $feedbackRating > 0 ? $feedbackRating . '/5' : '' ?></span>
                    </span>
                    <button type="button" class="purchase-feedback-button" data-open-order-feedback data-feedback-rating="<?= $feedbackRating ?>" data-feedback-text="<?= htmlspecialchars($feedbackText, ENT_QUOTES) ?>"><?= $feedbackRating > 0 ? 'Edit Rating' : 'Rate Order' ?></button>
                  </div>
                  <div class="purchase-footer-actions">
                    <?php if (!empty($paymongoReceiptOrders[(int)$order['id']])): ?>
                      <a class="payment-receipt-link" href="payment_receipt.php?order=<?= urlencode((string)$order['order_number']) ?>">View Payment Receipt</a>
                    <?php endif; ?>
                    <a class="message-admin-link" href="messaging.php?thread=<?= urlencode(threadKeyForOrder((int)$order['id'])) ?>">Message Admin</a>
                  </div>
                </div>
                
                <div class="purchase-ready-bill" data-order-completed-bill <?= $displayStatus === 'ready' ? '' : 'hidden' ?>>
                  <div class="purchase-ready-bill-copy"><strong>Your bill is ready</strong><span>View your payment details or print a copy.</span></div>
                  <a class="completed-bill-button" href="bill.php?order=<?= urlencode((string)$order['order_number']) ?>">View / Print Bill</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
  </main>

  <dialog class="order-feedback-modal" id="orderFeedbackModal" aria-labelledby="orderFeedbackTitle">
    <section class="order-feedback-card">
      <button type="button" class="order-feedback-close" data-close-order-feedback aria-label="Close feedback">×</button>
      <span class="auth-eyebrow">Customer Feedback</span>
      <h2 id="orderFeedbackTitle">Rate your order</h2>
      <p class="order-feedback-order" data-feedback-order-number>Completed order</p>
      <form id="orderFeedbackForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($feedbackCsrf, ENT_QUOTES) ?>">
        <input type="hidden" name="order_id" value="">
        <input type="hidden" name="rating" value="">
        <span class="order-feedback-rating-label">How was your order?</span>
        <div class="order-feedback-stars" role="radiogroup" aria-label="Star rating">
          <?php for ($feedbackStar = 1; $feedbackStar <= 5; $feedbackStar++): ?>
            <button type="button" class="order-feedback-star" data-feedback-star="<?= $feedbackStar ?>" role="radio" aria-checked="false" aria-label="<?= $feedbackStar ?> star<?= $feedbackStar === 1 ? '' : 's' ?>">★</button>
          <?php endfor; ?>
        </div>
        <label class="order-feedback-comment">
          <span>Feedback <small>(optional)</small></span>
          <textarea name="feedback" maxlength="600" placeholder="Tell us what you liked or what we can improve."></textarea>
          <span class="order-feedback-helper">Maximum 600 characters.</span>
        </label>
        <p class="order-feedback-message" data-feedback-message hidden></p>
        <div class="order-feedback-actions">
          <button type="button" class="order-feedback-cancel" data-close-order-feedback>Cancel</button>
          <button type="submit" class="order-feedback-submit">Submit Feedback</button>
        </div>
      </form>
    </section>
  </dialog>

  <?php if ($orderSuccess): ?>
    <?php
      $successNumbers = array_values(array_filter(array_map('strval', (array)($orderSuccess['order_numbers'] ?? []))));
      $successPayment = trim((string)($orderSuccess['payment_method'] ?? 'Online payment'));
      $successDeposit = (float)($orderSuccess['downpayment'] ?? 0);
      $successTest = !empty($orderSuccess['test_mode']);
      $successReference = trim((string)($orderSuccess['paymongo_reference'] ?? ''));
      $successTransaction = trim((string)($orderSuccess['paymongo_payment_id'] ?? $orderSuccess['paymongo_intent_id'] ?? ''));
      $successReceiptNumber = trim((string)($orderSuccess['receipt_number'] ?? ''));
      $successReceiptBarcode = trim((string)($orderSuccess['receipt_barcode'] ?? ''));
      $successPaidAtRaw = trim((string)($orderSuccess['paid_at'] ?? ''));
      $successPaidAt = $successPaidAtRaw !== '' ? (strtotime($successPaidAtRaw) ?: time()) : time();
      $successReceiptHref = $successNumbers ? 'payment_receipt.php?order=' . urlencode((string)$successNumbers[0]) : 'orders.php';
      $successOrderAnchor = $successNumbers
          ? 'order-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$successNumbers[0])
          : 'orders-list';
      $successBarcodeValue = $successReceiptBarcode !== '' ? $successReceiptBarcode : brilleReceiptBarcodeValue((string)($successNumbers[0] ?? 'RECEIPT'), (string)$successReference);
    ?>
    <dialog class="orders-success-modal payment-receipt-modal" id="ordersSuccessModal" aria-labelledby="ordersSuccessTitle">
      <section class="orders-success-card payment-receipt-card">
        <div class="receipt-modal-header">
          <div class="orders-success-check" aria-hidden="true">✓</div>
          <div>
            <span class="auth-eyebrow">PayMongo Payment Receipt</span>
            <h2 id="ordersSuccessTitle">Payment successful</h2>
            <p>Your <?= htmlspecialchars($successPayment) ?> payment has been received<?= $successTest ? ' in test mode' : '' ?>.</p>
          </div>
        </div>
        <div class="receipt-modal-grid">
          <?php if ($successReceiptNumber !== ''): ?><span><small>Receipt No.</small><strong><?= htmlspecialchars($successReceiptNumber) ?></strong></span><?php endif; ?><?php if ($successNumbers): ?><span><small>Order No.</small><strong><?= htmlspecialchars(implode(', ', $successNumbers)) ?></strong></span><?php endif; ?>
          <span><small>Amount Paid</small><strong><?= formatMoney($successDeposit) ?></strong></span>
          <span><small>Payment Method</small><strong><?= htmlspecialchars($successPayment) ?></strong></span>
          <span><small>Payment Date</small><strong><?= date('M d, Y · g:i A', $successPaidAt) ?></strong></span>
          <?php if ($successReference !== ''): ?><span><small>Checkout Reference</small><strong><?= htmlspecialchars($successReference) ?></strong></span><?php endif; ?>
          <?php if ($successTransaction !== ''): ?><span><small>PayMongo Transaction</small><strong class="receipt-transaction-id"><?= htmlspecialchars($successTransaction) ?></strong></span><?php endif; ?>
          <span><small>Next Status</small><strong>Admin Verification</strong></span>
        </div>
        <div class="receipt-modal-barcode">
          <div class="receipt-modal-barcode-copy">
            <small>Receipt Barcode</small>
            <p>Use or print this barcode together with your receipt for easier reference.</p>
          </div>
          <div class="receipt-modal-barcode-art"><?= brilleRenderCode39Svg($successBarcodeValue, 60) ?></div>
        </div>
        <div class="receipt-modal-note">Your receipt is saved in My Orders. You can reopen and print it anytime from the order card.</div>
        <div class="orders-success-actions receipt-modal-actions">
          <a class="account-primary-action" href="<?= htmlspecialchars($successReceiptHref) ?>">Print / View Receipt</a>
          <button class="account-secondary-action" type="button" id="ordersSuccessView" data-order-target="<?= htmlspecialchars($successOrderAnchor) ?>">View Order</button>
          <a class="account-secondary-action" href="shop.php">Order Another</a>
        </div>
      </section>
    </dialog>
    <script>
      (() => {
        const modal = document.getElementById('ordersSuccessModal');
        const viewOrder = document.getElementById('ordersSuccessView');
        if (!modal) return;
        if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
        const dismiss = () => {
          if (typeof modal.close === 'function' && modal.open) modal.close(); else modal.removeAttribute('open');
          try { history.replaceState({}, '', 'orders.php'); } catch (_) {}
        };
        viewOrder?.addEventListener('click', () => {
          const targetId = viewOrder.dataset.orderTarget || 'orders-list';
          dismiss();
          const target = document.getElementById(targetId) || document.getElementById('orders-list');
          if (target) {
            window.setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80);
          }
        });
        modal.addEventListener('cancel', (event) => { event.preventDefault(); dismiss(); });
        modal.addEventListener('click', (event) => { if (event.target === modal) dismiss(); });
      })();
    </script>
  <?php endif; ?>
  <script src="../assets/js/customer-order-feedback.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-order-feedback.js') ?>" defer></script>
  <script src="../assets/js/receipt-live.js?v=<?= filemtime(__DIR__ . '/../assets/js/receipt-live.js') ?>" defer></script>
  <script src="../assets/js/customer-orders-live.js?v=<?= filemtime(__DIR__ . '/../assets/js/customer-orders-live.js') ?>" defer></script>
</body>
</html>
