<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/functions.php';
require_once '../includes/payments.php';
require_once '../includes/paymongo.php';
require_once '../includes/payment_receipts.php';
require_once '../includes/receipt_live.php';

startCustomerSession();
ensureCustomerAuthSchema($conn);
ensureCheckoutPaymentSchema($conn);
ensurePaymongoSchema($conn);
ensurePaymentReceiptSchema($conn);
$customer = loadSignedInCustomer($conn);
session_write_close(); // polling must never block other requests of the same session
if (!$customer) receiptLiveJson(['ok' => false, 'error' => 'Not signed in.'], 401);

$customerId = (int)$customer['id'];
$name = trim((string)$customer['name']);
$contact = trim((string)($customer['contact'] ?? ''));

// Orders that belong to this customer (same ownership rule as the receipt page).
$ownSql = '(o.customer_id=?';
$ownTypes = 'i';
$ownParams = [$customerId];
if ($contact !== '') {
    $ownSql .= ' OR (o.customer_id IS NULL AND LOWER(TRIM(o.customer_name))=LOWER(TRIM(?)) AND o.customer_contact=?)';
    $ownTypes .= 'ss';
    $ownParams[] = $name;
    $ownParams[] = $contact;
}
$ownSql .= ')';

// List mode: which of my orders already have a paid receipt (used by My Orders to show "View Payment Receipt" live).
if (isset($_GET['list'])) {
    $stmt = $conn->prepare("SELECT DISTINCT o.order_number, COALESCE(r.receipt_status,'paid') AS receipt_status
        FROM orders o JOIN checkout_payment_orders po ON po.order_id=o.id JOIN paymongo_checkouts g ON g.payment_id=po.payment_id
        LEFT JOIN payment_receipts r ON r.payment_id=po.payment_id
        WHERE $ownSql AND g.state IN ('paid','test_paid') ORDER BY o.order_number");
    $stmt->bind_param($ownTypes, ...$ownParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $orders = array_values(array_unique(array_map(static fn($r) => (string)$r['order_number'], $rows)));
    receiptLiveJson(['ok' => true, 'orders' => $orders, 'signature' => md5(json_encode($rows))]);
}

// Single receipt mode.
$orderNumber = trim((string)($_GET['order'] ?? ''));
if ($orderNumber === '') receiptLiveJson(['ok' => false, 'error' => 'Missing order.'], 400);
$stmt = $conn->prepare("SELECT o.id FROM orders o JOIN checkout_payment_orders po ON po.order_id=o.id JOIN paymongo_checkouts g ON g.payment_id=po.payment_id
    WHERE o.order_number=? AND $ownSql AND g.state IN ('paid','test_paid') LIMIT 1");
$types = 's' . $ownTypes;
$params = array_merge([$orderNumber], $ownParams);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) receiptLiveJson(['ok' => true, 'paid' => false, 'signature' => '']);
receiptLiveJson(['ok' => true, 'paid' => true, 'signature' => receiptLiveOrderSignature($conn, (int)$row['id'])]);
