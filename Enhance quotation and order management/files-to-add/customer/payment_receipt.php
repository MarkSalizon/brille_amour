<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/functions.php';
require_once '../includes/payments.php';
require_once '../includes/paymongo.php';
require_once '../includes/payment_receipts.php';
require_once '../includes/receipt_live.php';
require_once '../includes/innovation.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
ensureCheckoutPaymentSchema($conn);
ensurePaymongoSchema($conn);
ensureInnovationSchema($conn);
$customer = loadSignedInCustomer($conn);
if (!$customer) {
    header('Location: ../index.php');
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

$sql = "SELECT o.id,o.order_number,o.customer_name,o.customer_contact,o.order_type,o.description,o.quantity,o.unit_price,o.total_amount,o.delivery_fee,o.deposit_paid,o.balance,o.fulfillment_method,o.delivery_address,o.due_date,o.pickup_time,o.status,o.created_at,
               p.id AS checkout_payment_id,p.reference_number,p.amount AS payment_amount,p.payment_method,p.payment_method_other,p.status AS payment_status,p.created_at AS payment_created_at,
               g.state AS gateway_state,g.paid_payment_id,g.payment_intent_id,g.livemode,g.created_at AS gateway_created_at
        FROM orders o
        JOIN checkout_payment_orders po ON po.order_id=o.id
        JOIN checkout_payments p ON p.id=po.payment_id
        JOIN paymongo_checkouts g ON g.payment_id=p.id
        WHERE o.order_number=? AND (o.customer_id=?";
$params = [$orderNumber, $customerId];
$types = 'si';
if ($contact !== '') {
    $sql .= " OR (o.customer_id IS NULL AND LOWER(TRIM(o.customer_name))=LOWER(TRIM(?)) AND o.customer_contact=?)";
    $params[] = $name;
    $params[] = $contact;
    $types .= 'ss';
}
$sql .= ") AND g.state IN ('paid','test_paid') ORDER BY p.id DESC LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$receipt) {
    http_response_code(404);
    header('Location: orders.php');
    exit;
}
$paymentDeliveryFee = 0.0;
$feeStmt = $conn->prepare('SELECT COALESCE(SUM(o.delivery_fee),0) AS fee FROM checkout_payment_orders po JOIN orders o ON o.id=po.order_id WHERE po.payment_id=?');
if ($feeStmt) { $pid=(int)$receipt['checkout_payment_id']; $feeStmt->bind_param('i',$pid); $feeStmt->execute(); $paymentDeliveryFee=(float)($feeStmt->get_result()->fetch_assoc()['fee']??0); $feeStmt->close(); }
$receiptRecord = ensureReceiptForPayment($conn, (int)$receipt['checkout_payment_id']) ?: paymentReceiptByPayment($conn, (int)$receipt['checkout_payment_id']);
$paymentHistory = paymentHistoryForOrder($conn, (int)$receipt['id']);
$business = paymentReceiptBusinessDetails();
$liveSignature = receiptLiveOrderSignature($conn, (int)$receipt['id']);
$receiptStatus = paymentReceiptStatusLabel((string)($receiptRecord['receipt_status'] ?? 'paid'));

$paymentMethod = paymentMethodLabel($receipt);
$paymentAmount = (float)$receipt['payment_amount'];
$orderTotal = (float)$receipt['total_amount'];
$balance = max(0, (float)$receipt['balance']);
$paymentDate = strtotime((string)($receipt['payment_created_at'] ?? $receipt['gateway_created_at'] ?? 'now')) ?: time();
$isTest = (string)$receipt['gateway_state'] === 'test_paid' || !(bool)$receipt['livemode'];
$transactionId = trim((string)($receipt['paid_payment_id'] ?? ''));
if ($transactionId === '') {
    $transactionId = trim((string)($receipt['payment_intent_id'] ?? ''));
}
$barcodeValue = (string)($receiptRecord['barcode_value'] ?? brilleReceiptBarcodeValue((string)$receipt['order_number'], (string)$receipt['reference_number']));

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

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Payment Receipt <?= htmlspecialchars((string)$receipt['order_number']) ?> - Brille Amour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f6f4f2;color:#2b2325;font-family:Outfit,system-ui,sans-serif;font-size:13px}.receipt-shell{max-width:720px;margin:0 auto;padding:18px 14px 36px}.receipt-actions{display:flex;gap:8px;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap}.receipt-actions a,.receipt-actions button{border:0;border-radius:10px;padding:9px 12px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.receipt-actions a{background:#fff;color:#5d3945;border:1px solid #e7dfe2}.receipt-actions button{background:#7d3f55;color:#fff}.receipt-paper{background:#fff;border:1px solid #eadfe3;border-radius:16px;box-shadow:0 10px 28px rgba(71,40,50,.07);overflow:hidden}.receipt-head{display:flex;justify-content:space-between;gap:14px;padding:16px 18px 12px;border-bottom:1px dashed #eadfe3}.receipt-brand{display:flex;gap:10px;align-items:center}.receipt-brand img{width:42px;height:42px;object-fit:contain;border-radius:10px}.receipt-brand strong{display:block;font-size:17px}.receipt-brand span{display:block;color:#7f6d73;font-size:11px}.receipt-title{text-align:right}.receipt-title span{display:block;font-size:10px;letter-spacing:.1em;color:#8f6d78;font-weight:800}.receipt-title strong{display:block;font-size:16px;margin-top:2px}.receipt-title small{color:#7e7074;font-size:11px}.receipt-status{margin:10px 18px 8px;padding:8px 10px;border-radius:10px;background:#eef8f1;color:#25633a;display:flex;justify-content:space-between;gap:10px;font-weight:700;font-size:12px}.receipt-status.test{background:#fff5dc;color:#7a5a14}.receipt-summary{display:grid;grid-template-columns:1fr 1fr;gap:8px 18px;padding:8px 18px 10px}.summary-line{display:flex;justify-content:space-between;gap:10px;padding:3px 0;border-bottom:1px dotted #f0e5e8}.summary-line span{color:#78696e}.summary-line strong{text-align:right;word-break:break-word}.receipt-id{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:10px}.receipt-fulfillment{margin:0 18px 10px;padding:8px 10px;border:1px solid #eee4e7;border-radius:10px;background:#fcfafb;display:grid;grid-template-columns:110px 1fr;gap:4px 10px}.receipt-fulfillment span{color:#826b73}.receipt-fulfillment strong{font-weight:600;word-break:break-word}.receipt-item{margin:0 18px 10px;border:1px solid #eee4e7;border-radius:10px;overflow:hidden}.receipt-item-row{display:grid;grid-template-columns:minmax(0,1fr) 46px 92px;gap:8px;align-items:center;padding:9px 10px}.receipt-item-row span{color:#75666b}.receipt-item-row strong:last-child{text-align:right}.receipt-totals{margin:0 18px 10px;padding:9px 10px;border-top:1px dashed #e7dce0}.receipt-row{display:flex;justify-content:space-between;gap:12px;padding:3px 0}.receipt-row span{color:#78696e}.receipt-row strong{text-align:right}.receipt-totals .receipt-row.total{font-size:15px;font-weight:800;border-top:1px solid #eee4e7;margin-top:4px;padding-top:7px}.receipt-barcode-wrap{margin:0 18px 10px;padding:9px 12px;border:1px solid #eee4e7;border-radius:10px;background:#fff}.receipt-barcode-meta{display:flex;justify-content:space-between;gap:10px;margin-bottom:5px;color:#7b6970;font-size:10px}.receipt-barcode-svg{display:block;width:100%;max-height:72px}.receipt-barcode-caption{margin-top:3px;color:#8f7d83;font-size:10px;text-align:center}.receipt-history{margin:0 18px 10px;border:1px solid #eee4e7;border-radius:10px}.receipt-history summary{cursor:pointer;padding:8px 10px;font-weight:700;color:#6e5360}.receipt-history-body{padding:0 10px 8px}.receipt-history .receipt-row{font-size:11px}.receipt-foot{padding:10px 18px 14px;text-align:center;color:#7e7074;border-top:1px dashed #eee4e7;font-size:11px}.receipt-foot strong{color:#5d3945}.receipt-mini-note{margin:0 18px 10px;color:#7e7074;text-align:center;font-size:10px}@media(max-width:620px){.receipt-shell{padding:10px}.receipt-head{display:block}.receipt-title{text-align:left;margin-top:8px}.receipt-summary{grid-template-columns:1fr}.receipt-item-row{grid-template-columns:minmax(0,1fr) 42px 86px}.receipt-fulfillment{grid-template-columns:95px 1fr}}@media print{body{background:#fff;font-size:11px}.receipt-shell{max-width:100%;padding:0}.receipt-actions{display:none}.receipt-paper{border:0;box-shadow:none;border-radius:0}.receipt-head{padding-top:4px}.receipt-history{display:none}.receipt-mini-note{display:none}.receipt-barcode-svg{max-height:58px}}
  </style>
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body>
<main class="receipt-shell" data-receipt-live data-live-api="receipt_live_api.php?order=<?= urlencode((string)$receipt['order_number']) ?>" data-live-page="payment_receipt.php?order=<?= urlencode((string)$receipt['order_number']) ?>" data-live-signature="<?= htmlspecialchars($liveSignature, ENT_QUOTES) ?>" data-live-swap=".receipt-paper">
  <div class="receipt-actions no-print">
    <a href="orders.php#order-<?= htmlspecialchars(preg_replace('/[^A-Za-z0-9_-]/','-',(string)$receipt['order_number'])) ?>">← Back to My Orders</a>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><a href="payment_receipt_pdf.php?order=<?= urlencode((string)$receipt['order_number']) ?>">Download PDF</a><button type="button" onclick="window.print()">Print</button></div>
  </div>
  <article class="receipt-paper">
    <header class="receipt-head">
      <div class="receipt-brand"><img src="../assets/img/image.png" alt="Brille Amour logo"><div><strong><?= htmlspecialchars($business['name'] ?: 'Brille Amour') ?></strong><span>Prints &amp; Crafts<?php if($business['contact']!==''): ?> · <?= htmlspecialchars($business['contact']) ?><?php endif; ?></span></div></div>
      <div class="receipt-title"><span>PAYMENT RECEIPT</span><strong><?= htmlspecialchars((string)($receiptRecord['receipt_number'] ?? $receipt['order_number'])) ?></strong><small><?= date('M d, Y · g:i A',$paymentDate) ?></small></div>
    </header>

    <div class="receipt-status <?= in_array((string)($receiptRecord['receipt_status'] ?? 'paid'),['refunded','reversed'],true) ? 'test' : ($isTest ? 'test' : '') ?>"><span><?= htmlspecialchars($receiptStatus) ?></span><span><?= htmlspecialchars($paymentMethod) ?></span></div>

    <section class="receipt-summary">
      <div class="summary-line"><span>Customer</span><strong><?= htmlspecialchars((string)$receipt['customer_name']) ?></strong></div>
      <div class="summary-line"><span>Order No.</span><strong><?= htmlspecialchars((string)$receipt['order_number']) ?></strong></div>
      <div class="summary-line"><span>Reference</span><strong><?= htmlspecialchars((string)$receipt['reference_number']) ?></strong></div>
      <?php if($transactionId!==''): ?><div class="summary-line"><span>PayMongo ID</span><strong class="receipt-id"><?= htmlspecialchars($transactionId) ?></strong></div><?php endif; ?>
    </section>

    <?php $fulfillmentMethod = strtolower(trim((string)($receipt['fulfillment_method'] ?? 'pickup'))); $fulfillmentLabel = $fulfillmentMethod === 'delivery' ? 'Delivery' : 'Pickup'; ?>
    <section class="receipt-fulfillment">
      <span>Order Method</span><strong><?= htmlspecialchars($fulfillmentLabel) ?></strong>
      <?php if($fulfillmentMethod==='delivery'): ?><span>Address</span><strong><?= htmlspecialchars(trim((string)$receipt['delivery_address']) !== '' ? (string)$receipt['delivery_address'] : 'Not provided') ?></strong><?php else: ?><span>Location</span><strong>Brille Amour Prints &amp; Crafts</strong><?php endif; ?>
      <span>Schedule</span><strong><?= htmlspecialchars(trim((string)$receipt['due_date']) . (trim((string)$receipt['pickup_time'])!=='' ? ' · ' . trim((string)$receipt['pickup_time']) : '')) ?></strong>
    </section>

    <section class="receipt-item">
      <div class="receipt-item-row"><div><strong><?= htmlspecialchars((string)$receipt['order_type']) ?></strong></div><span>x<?= (int)$receipt['quantity'] ?></span><strong><?= formatMoney($orderTotal) ?></strong></div>
    </section>

    <section class="receipt-totals">
      <?php if($fulfillmentMethod==='delivery' && $paymentDeliveryFee>0): ?><div class="receipt-row"><span>Delivery Fee</span><strong><?= formatMoney($paymentDeliveryFee) ?></strong></div><?php endif; ?>
      <div class="receipt-row"><span>Order Total</span><strong><?= formatMoney($orderTotal) ?></strong></div>
      <div class="receipt-row"><span>Amount Paid</span><strong><?= formatMoney($paymentAmount) ?></strong></div>
      <div class="receipt-row total"><span>Remaining Balance</span><strong><?= formatMoney($balance) ?></strong></div>
    </section>

    <section class="receipt-barcode-wrap">
      <div class="receipt-barcode-meta"><span><?= htmlspecialchars((string)($receiptRecord['receipt_number'] ?? $receipt['order_number'])) ?></span><span><?= htmlspecialchars((string)$receipt['reference_number']) ?></span></div>
      <?= brilleRenderCode39Svg($barcodeValue, 48) ?>
    </section>

    <?php if (count($paymentHistory) > 1): ?>
      <details class="receipt-history"><summary>Payment history (<?= count($paymentHistory) ?> transactions)</summary><div class="receipt-history-body">
        <?php foreach($paymentHistory as $hist): $hr=ensureReceiptForPayment($conn,(int)$hist['id']) ?: paymentReceiptByPayment($conn,(int)$hist['id']); ?>
          <div class="receipt-row"><span><?= htmlspecialchars((string)($hr['receipt_number'] ?? $hist['reference_number'])) ?></span><strong><?= formatMoney((float)$hist['allocated']) ?> · <?= htmlspecialchars(paymentReceiptStatusLabel((string)($hr['receipt_status'] ?? 'paid'))) ?></strong></div>
        <?php endforeach; ?>
      </div></details>
    <?php endif; ?>

    <?php if(!empty($receiptRecord['status_note'])): ?><div class="receipt-mini-note"><strong>Status note:</strong> <?= htmlspecialchars((string)$receiptRecord['status_note']) ?></div><?php endif; ?>
    <footer class="receipt-foot"><strong>Thank you for choosing Brille Amour.</strong> This receipt is generated from your recorded PayMongo transaction.</footer>
  </article>
</main>
<script src="../assets/js/receipt-live.js?v=<?= filemtime(__DIR__ . '/../assets/js/receipt-live.js') ?>" defer></script>
</body>
</html>
